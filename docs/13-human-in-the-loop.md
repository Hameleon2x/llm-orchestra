**Language:** **English** · [Русский](ru/13-human-in-the-loop.md)

# Human-in-the-loop (pause and resume)

Sometimes a tool can't return a result on its own — it needs something from outside the process: a user's answer, an approval, an external event. Instead of blocking the worker (the answer arrives in a *later* HTTP request, minutes away), the tool **pauses the run** and the loop **resumes** once the input is supplied. This is the elicitation / human-in-the-loop pattern.

The `Runner` stays stateless: there is no dedicated "resume" API. Resuming is just calling `run()` again with the missing results filled into the history.

## The suspend signal

A tool requests a pause by returning `Tool\Dto\Result::suspend()` from `execute()` instead of `ok()` / `error()`:

```php
public function execute(array $args): Result
{
    // validate args, then pause: the result will be the user's answer, supplied on resume
    return Result::suspend();
}
```

`Result::isSuspended()` reports it. No data is attached — the result doesn't exist yet.

## What the Runner does

When a tool call resolves to `suspend()`, the `Runner`:

1. **Does not** write a `tool` message for that call — its result will come from outside.
2. Keeps executing the **other** tool calls of the same turn normally (non-suspend tools run and get their `tool` messages).
3. After the turn, if any call suspended, **stops the loop** and returns a suspended `Agent\Dto\Result` instead of calling the model again.

```php
$result = $runner->run($history, $toolbox, $systemPromptFn, $config, $emit);

if ($result->suspended) {
    // $result->pendingToolCallIds — ids whose results you must supply to resume.
    // The TOOL_CALL events already fired, so your UI can render each prompt/widget.
    persistPending($dialogId, $result->pendingToolCallIds);
    return; // worker finishes; the run is paused
}
```

`Agent\Dto\Result` carries:

| Property              | Type       | Meaning                                                                                  |
|-----------------------|------------|------------------------------------------------------------------------------------------|
| `$suspended`          | `bool`     | `true` when the run paused waiting for external input. `$content` / `$error` are `null`. |
| `$pendingToolCallIds` | `string[]` | Tool-call ids whose results must be supplied before the run can resume.                  |

## How the answer gets back: resume = run again

There is no helper to "inject the answer" — the primitive is `Message::tool($toolCallId, $content)`, the same `tool` message the `Runner` writes for ordinary tools. To resume:

1. For **each** pending id, append a `Message::tool($pendingId, $answer)` to the persisted history. `$answer` is whatever the external input produced, serialised however your tool's `appendToSystemPromptAfterUse()` documents (e.g. `{"answer":"..."}`).
2. Call `run()` again with that history. The model sees the answers as the tools' results and continues — exactly as if the tools had returned them synchronously.

```php
// the user answered one of the pending calls
recordToolMessage($dialogId, $toolCallId, json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE));

// resume only when EVERY pending call has an answer (see "the protocol rule" below)
if (!allPendingAnswered($dialogId)) {
    return; // keep waiting for the rest
}

$history = loadHistory($dialogId); // Message[] ending with the assistant tool_calls + a tool message per call
$result  = $runner->run($history, $toolbox, $systemPromptFn, $config, $emit);
// the model continues from the answers
```

`loadHistory()` is yours — typically you rebuild `Message[]` from however you persisted the dialog ([07-history-serialization.md](07-history-serialization.md) covers the wire format). The only requirement the `Runner` cares about is a valid history (next section).

## Resuming: closing pending calls before the next model call

In the OpenAI-compatible wire format, **every** `tool_call` in an assistant message must have a matching `tool` message before the next assistant message. `pendingToolCallIds` is a list because the model can ask several things in one turn (parallel tool calls) — persist the set, fill it as answers arrive.

You don't have to gate this perfectly yourself: `run()` is **resumable**. Before calling the model it completes any tool call still missing a `tool` message — the same path as a normal turn (ordinary tools run and append their result; suspend tools re-pause). Only the current unfinished turn ever has unanswered calls: concluded turns are always fully closed (ordinary turns before the next assistant; a turn cut off by the tool-call limit gets error results). So:

- **answers added → continue:** a complete history has nothing to re-run; the model continues from the answers;
- **resumed too early → re-pause:** resume a suspended run before all answers are in, and the still-open suspend calls simply re-suspend (idempotent), returning `suspended` again with the remaining ids — no error, no malformed request;
- **interrupted mid-execution → recover:** a run whose worker died after the assistant turn but before all results were written re-executes the unfinished calls and continues.

Caveat for the interrupted/crash case: re-execution is **at-least-once** — make side-effecting tools idempotent or guard them. `TOOL_CALL` is emitted once, when the model first requests a call, so re-execution emits only the missing `TOOL_RESULT` — no duplicate call events. (The suspend happy-path re-runs nothing at all: once the answer is a `tool` message in history, the call counts as answered.)

## Matching is by id, not position

Resume `tool` messages are matched to their calls by `tool_call_id`, not by order. The non-suspend tools of the suspend turn already wrote their `tool` messages; you append the suspended ones on resume. The final order (`A, B, q1, q2` even though the turn was `A, q1, B, q2`) is irrelevant — only that every id is closed.

## Caveat: don't batch an action that depends on a pending answer

Non-suspend tools in the suspend turn run **immediately**, before the answer exists. So the model must not, in the same turn, call a tool whose effect depends on the answer it's still waiting for — e.g. `ask_user("delete?")` together with `delete_record()` would delete before the user replies. Such gated actions belong on a **later** turn, after the answer is in. State this in the suspend-tool's `getDescription()` ("call it alone"); the `Runner` cannot infer the semantic dependency.

## Events

A suspended call still emits `TOOL_CALL` (so the UI can render the question or widget) but **no** `TOOL_RESULT` — there is no result yet. On resume you write the `tool` message yourself; the `Runner` does not emit a synthetic `TOOL_RESULT` for it. See [06-events.md](06-events.md).

## Sketch: an `ask_user` tool

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
        return 'Ask the user a question with predefined options and wait for their choice. '
            . 'Call it alone — not together with tools whose effect depends on the answer.';
    }

    public function appendToSystemPromptAfterUse(): string
    {
        return 'ask_user resolves to {"answer": "..."} — the option the user picked (or free text). '
            . 'Continue from that answer.';
    }

    public function getParameters(): array
    {
        return [
            new Property('question', 'string', 'The question to ask', true),
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

The persistence, the "all answered?" gate, and the per-question UI live in your application — the package gives you the pause signal, the pending ids, and the stateless resume.

## See also

- [04-tools.md](04-tools.md) — `Result::ok()` / `error()` / `suspend()`.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the loop and `Agent\Dto\Result`.
- [06-events.md](06-events.md) — `TOOL_CALL` without `TOOL_RESULT` on suspend.
- [07-history-serialization.md](07-history-serialization.md) — persist and rebuild `Message[]` for resume.
