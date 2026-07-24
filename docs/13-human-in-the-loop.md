**Language:** **English** · [Русский](ru/13-human-in-the-loop.md)

# Human-in-the-loop (pause and resume)

Sometimes a tool can't return a result on its own — it needs something from outside the process: a user's answer, an approval, an external event. Instead of blocking the worker (the answer will arrive in the *next* HTTP request, minutes later), the tool **pauses the run**, and the loop **resumes** once the input arrives. This is the elicitation / human-in-the-loop pattern.

`Runner` stays stateless: there is no separate "resume" API. Resuming is just calling `run()` again with the history that now has the missing results appended.

## The pause signal

A tool asks for a pause by returning `Tool\Dto\Result::suspend()` from `execute()` instead of `ok()` / `error()`:

```php
public function execute(array $args): Result
{
    // validate the arguments and pause: the result will be the user's answer, supplied on resume
    return Result::suspend();
}
```

`Result::isSuspended()` reports this. There's no data — the result doesn't exist yet.

## What the Runner does

When a tool call returns `suspend()`, `Runner`:

1. **Does not** write a `tool` message for that call — its result will come from outside.
2. Keeps executing the **other** calls of the same turn as usual (non-suspend tools run and get their `tool` messages).
3. After the turn, if at least one call is suspended, **stops the loop** and returns a suspended `Agent\Dto\Result` instead of calling the model again.

```php
$result = $runner->run($history, $toolbox, $systemPromptFn, $config, $emit);

if ($result->suspended) {
    // $result->pendingToolCallIds — ids of the calls whose results must be supplied to resume.
    // TOOL_CALL events have already fired, so the UI can render each question/widget.
    persistPending($dialogId, $result->pendingToolCallIds);
    return; // the worker finishes; the run is paused
}
```

In `Agent\Dto\Result` this shows up in two fields: `$suspended` equals `true` (while `$content` and `$error` are `null`), and `$pendingToolCallIds` holds the ids of the calls whose results must be supplied before resuming. The reason for stopping is duplicated in `$finish` — it will be `Finish::SUSPENDED`.

## How the answer gets back: resume = run again

There is no "inject the answer" helper — the primitive is `Message::tool($toolCallId, $content)`, the same `tool` message that `Runner` writes for ordinary tools. To resume:

1. For **each** pending id, append `Message::tool($pendingId, $answer)` to the saved history. `$answer` is whatever the external input produced, serialized the way your tool's `firstUseHint()` describes (e.g., `{"answer":"..."}`).
2. Call `run()` again with that history. The model sees the answers as tool results and continues — exactly as if the tools had returned them synchronously.

```php
// the user answered one of the pending calls
recordToolMessage($dialogId, $toolCallId, json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE));

// resume only once ALL pending calls are answered (see "the protocol rule" below)
if (!allPendingAnswered($dialogId)) {
    return; // wait for the rest
}

$history = loadHistory($dialogId); // Message[], ending with the assistant's tool_calls + one tool message per call
$result  = $runner->run($history, $toolbox, $systemPromptFn, $config, $emit);
// the model continues from the answers
```

`loadHistory()` is your own code: typically you rebuild `Message[]` from however you saved the dialog ([07-history-serialization.md](07-history-serialization.md) describes the format). `Runner` requires only a valid history (next section).

## Resuming: closing pending calls before calling the model

In the OpenAI-compatible format, **every** `tool_call` of an assistant message must have a matching `tool` message before the next `assistant`. `pendingToolCallIds` is a list because the model can ask several things in one turn (parallel calls): keep the set and fill it in as answers arrive.

You don't have to gate this perfectly yourself: `run()` is **resumable**. Before calling the model, it resolves any tool_call that still has no `tool` message — the same way as an ordinary turn (ordinary tools execute and append a result; suspend pauses again). Unanswered calls only ever occur on the current unfinished turn: completed turns are always closed (ordinary ones — before the next assistant; a turn cut off by the limit — with tool errors). So:

- **answers supplied → we continue:** in a complete history there's nothing left to resolve, the model continues from the answers;
- **resumed too early → paused again:** if you run the suspended run before all the answers have arrived, the still-open suspend calls simply pause again (idempotently), and `suspended` is returned with the remaining ids; no error, no "broken" request;
- **interrupted mid-execution → recovery:** a run whose worker died after the assistant's turn but before all the results were recorded will resolve the unfinished calls and continue.

A caveat for the interrupted/crash case: re-execution is **at-least-once**, so make tools with side effects idempotent or guard them. `TOOL_CALL` is emitted once, the first time the model requests the call, so re-execution only sends the missing `TOOL_RESULT` — no duplicate call events. (On the happy path, suspend re-runs nothing at all: as soon as the answer lands in the history as a `tool` message, the call counts as answered.)

## Matching by id, not by order

On resume, `tool` messages are matched to calls by `tool_call_id`, not by order. The non-suspend tools of the turn have already written their `tool` messages; you append the suspended ones on resume. The final order (`A, B, q1, q2`, even though the turn was `A, q1, B, q2`) doesn't matter — what matters is that every id is closed.

## Caveat: don't put an action that depends on the expected answer in the same turn

The non-suspend tools of the turn execute **right away**, before the answer exists. So the model must not call, in the same turn, a tool whose effect depends on an answer it hasn't received yet — for example, `ask_user("delete?")` together with `delete_record()` would delete before the user answers. Such dependent actions belong on the **next** turn, once the answer is in. State this in the suspend tool's `getDescription()` ("call it separately"); `Runner` has no way to know the semantic dependency.

## Events

A suspended call still emits `TOOL_CALL` (so the UI can render the question or widget), but **not** `TOOL_RESULT` — there's no result yet. On resume you write the `tool` message yourself; `Runner` does not emit a synthetic `TOOL_RESULT` for it. See [06-events.md](06-events.md).

## Sketch: the `ask_user` tool

```php
<?php
use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class AskUserTool extends AbstractTool
{
    public function getName(): string { return 'ask_user'; }

    public function getDescription(): string
    {
        return 'Ask the user a question with ready-made options and wait for their choice. '
            . 'Call it separately — not together with tools whose effect depends on the answer.';
    }

    public function firstUseHint(): string
    {
        return 'ask_user returns {"answer": "..."} — the option the user picked (or free text). '
            . 'Continue from that answer.';
    }

    public function getParameters(): array
    {
        return [
            new Property('question', 'string', 'The question text', true),
            new Property('options', 'array', 'Answer options', true, ['type' => 'string']),
        ];
    }

    public function execute(array $args): Result
    {
        $options = array_values(array_filter(array_map('strval', (array)($args['options'] ?? []))));
        if (trim((string)($args['question'] ?? '')) === '' || count($options) < 2) {
            return Result::error('question and at least 2 options are required');
        }
        return Result::suspend(); // the answer is supplied on resume
    }

    public function shouldDisplay(array $args): bool { return true; }
}
```

Persistence, the "all answered?" gate, and the UI for each question live on the application side; the package provides the pause signal, the list of pending ids, and stateless resume.

## See also

- [04-tools.md](04-tools.md) — `Result::ok()` / `error()` / `suspend()`.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the loop and `Agent\Dto\Result`.
- [06-events.md](06-events.md) — `TOOL_CALL` without `TOOL_RESULT` on pause.
- [07-history-serialization.md](07-history-serialization.md) — saving and rebuilding `Message[]` for resume.
