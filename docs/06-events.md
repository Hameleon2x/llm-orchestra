**Language:** **English** · [Русский](ru/06-events.md)

# Events

`Runner::run()` accepts an optional `$emit` callback that fires at several points inside every iteration. Use it to drive a live chat UI or persist the dialog as it unfolds.

## Signature

```php
$emit = function (string $event, string $content, array $meta): void { /* ... */ };
$runner->run($messages, $toolbox, $systemPromptFn, $config, $emit);
```

A plain `callable`; `$event` is one of the constants on `Agent\Enum\Event`.

## The events

`Hameleon2x\Llm\Agent\Enum\Event`: three turn events plus two about model call failures.

### `Event::ASSISTANT_MESSAGE` — `'assistant_message'`

Fires once per turn, immediately after the model returns a response that contains `tool_calls`. (A final answer without tool calls ends the loop and is not emitted — it comes back via `Result::$content`.)

- `$content` — assistant text alongside the tool calls (may be empty).
- `$meta['tool_calls']` — array of tool calls in OpenAI wire format (`['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '{...}']]`), produced by `Factory\ToolCallFactory::toArray()`.
- `$meta['extra']` — provider data via the `capture` map: model reasoning, citations, refusals.
- `$meta['usage']` — this turn's consumption (`Dto\Usage::toArray()`).
- `$meta['model']` — the key of the model that answered this turn. Differs from the requested one after a switch.

### `Event::TOOL_CALL` — `'tool_call'`

Fires once per call the model requested, emitted up front when the assistant response arrives — before any `execute()`. (On resume, re-executed calls do not re-fire `TOOL_CALL`; only their `TOOL_RESULT`.)

- `$content` — tool name (also in `$meta['tool']`).
- `$meta['tool_call_id']` — OpenAI `id`; pairs this with the matching `TOOL_RESULT`.
- `$meta['tool']` — tool name.
- `$meta['args']` — decoded arguments as an assoc array (`ToolCall::getArguments()`).

### `Event::TOOL_RESULT` — `'tool_result'`

Fires once per tool invocation: right after `execute()` returns, and for a call that did not complete (rejected by the argument check, cut off by the call limit, or thrown out of `execute()`) — at the moment the loop closes it with an error.

- `$content` — `Result::toJsonArray()` encoded as JSON (`JSON_UNESCAPED_UNICODE`). For successes, the `data` payload; for errors, `{"error":"..."}`. On the tool's first use in the dialog the `firstUseHint()` note is added here under the `firstUseHintKey()` key.
- `$meta['tool_call_id']` — same id as the matching `TOOL_CALL`.
- `$meta['tool']` — tool name.
- `$meta['ok']` — `bool`, value of `Tool\Dto\Result::$ok`. Distinguishes tool errors (the tool ran but reported failure) from successes.
- `$meta['guard']` — `true` when the call was rejected by the argument check and the tool never ran (see `Config::$toolArgsGuard`). A signal for the UI not to render a widget for a corrupted call.

### `Event::ATTEMPT_FAILED` — `'attempt_failed'`

A model call attempt failed. Fires both for intermediate failures (a retry follows) and for the last one.

- `$content` — the error category (`Error\ErrorCategory`).
- `$meta['model']`, `$meta['provider']` — where the failure happened.
- `$meta['attempt']` — attempt number for that model.
- `$meta['category']`, `$meta['message']` — the category and the technical message.
- `$meta['will_retry']` — whether a retry follows; `$meta['delay']` — in how many seconds.

Show "retrying" only when `will_retry` is set: otherwise the next thing to arrive is either a model switch or the run error.

### `Event::MODEL_FALLBACK` — `'model_fallback'`

Work was handed over to the next model in the chain: retrying the previous one didn't help.

- `$content` — the new model's key.
- `$meta['from']`, `$meta['to']` — previous and new model keys.

The run then continues on the new model (`Config::$stickyFallback`), so the event fires once per switch rather than on every subsequent turn.

Order within a single turn: `ASSISTANT_MESSAGE` → one `TOOL_CALL` per requested call (all up front, when the model's response arrives) → one `TOOL_RESULT` per call as it executes → loop continues.

**Suspended calls (human-in-the-loop):** a tool that returns `Result::suspend()` still emits `TOOL_CALL` (so the UI can render the prompt/widget) but **no** `TOOL_RESULT` — there is no result yet. The run stops after the turn. On resume the runner re-executes only the still-unanswered calls and emits just their `TOOL_RESULT` (the up-front `TOOL_CALL` already fired), so persisted events don't duplicate. See [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Use case: live UI progress

Push each event over WebSocket / SSE / long-polling to the browser. Render an assistant bubble on `ASSISTANT_MESSAGE`, a "calling X..." indicator on `TOOL_CALL`, replace it with the result on `TOOL_RESULT`.

```php
<?php
use Hameleon2x\Llm\Agent\Enum\Event;

$emit = function (string $event, string $content, array $meta) use ($channel): void {
    switch ($event) {
        case Event::ASSISTANT_MESSAGE:
            $channel->send(['kind' => 'assistant', 'text' => $content, 'tool_calls' => $meta['tool_calls']]);
            return;
        case Event::TOOL_CALL:
            $channel->send([
                'kind' => 'tool_call', 'tool_call_id' => $meta['tool_call_id'],
                'tool' => $meta['tool'], 'args' => $meta['args'],
            ]);
            return;
        case Event::TOOL_RESULT:
            $channel->send([
                'kind' => 'tool_result', 'tool_call_id' => $meta['tool_call_id'],
                'tool' => $meta['tool'], 'ok' => $meta['ok'], 'json' => $content,
            ]);
            return;
    }
};
```

## Use case: persist the dialog to a database

`Result::$messages` already gives you the full history at the end of the run, so a single save after `run()` returns is usually enough. Stream events into the DB when you need a row-per-event audit trail (timestamps, partial transcript if a worker dies mid-run):

```php
<?php
use Hameleon2x\Llm\Agent\Enum\Event;

$dialogId = 42;

$emit = function (string $event, string $content, array $meta) use ($db, $dialogId): void {
    $row = ['dialog_id' => $dialogId, 'event' => $event, 'content' => $content, 'created_at' => microtime(true)];

    switch ($event) {
        case Event::ASSISTANT_MESSAGE:
            $row['meta'] = json_encode(['tool_calls' => $meta['tool_calls']], JSON_UNESCAPED_UNICODE);
            break;
        case Event::TOOL_CALL:
            $row['tool_call_id'] = $meta['tool_call_id'];
            $row['tool']         = $meta['tool'];
            $row['meta']         = json_encode(['args' => $meta['args']], JSON_UNESCAPED_UNICODE);
            break;
        case Event::TOOL_RESULT:
            $row['tool_call_id'] = $meta['tool_call_id'];
            $row['tool']         = $meta['tool'];
            $row['ok']           = (int)$meta['ok'];
            break;
    }

    $db->insert('llm_dialog_events', $row); // pseudocode
};
```

If the run is killed (worker timeout, deploy mid-flight), the events written so far are still in the DB — you have a partial transcript instead of nothing.

## See also

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — where `$emit` is called from.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — `TOOL_CALL` without `TOOL_RESULT` when a tool suspends.
- [03-logging.md](03-logging.md) — PSR-3 channel for retries/fallbacks; complementary to this UI-facing stream.
