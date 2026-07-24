**Language:** **English** · [Русский](ru/08-config-reference.md)

# Run config reference

A full walkthrough of [`Agent\Dto\Config`](../src/Agent/Dto/Config.php) — the parameters of a single `Runner::run()` call. Model settings themselves live in the catalog, see [02-catalog-and-fallback.md](02-catalog-and-fallback.md).

## Fields

**Which model to use**

- **`model`** (`?string`, default `null`) — the catalog model key. `null` — the catalog's default model.
- **`fallback`** (`?string[]`, `null`) — the backup model chain for this run. `null` — the catalog's chain.
- **`policy`** (`?ErrorPolicy`, `null`) — the retry policy for this run. `null` — the model's or the catalog's policy.
- **`stickyFallback`** (`bool`, `true`) — after a switch, continue the run on the model that answered.

**Run bounds**

- **`maxTurns`** (`int`, `40`) — how many times the model can be called. One turn is one request.
- **`maxToolCalls`** (`int`, `30`) — how many tools can be executed over the whole run, not per turn.
- **`deadlineSeconds`** (`?float`, `null`) — the maximum run duration in seconds.

**What goes into the request**

- **`params`** (`GenerationParams`, empty) — `temperature`, `topP`, `maxTokens`, `seed` for this run.
- **`extraParams`** (`array`, `[]`) — extra payload fields for every request of the run.
- **`toolChoice`** (`string|array`, `'auto'`) — `'auto'`, `'required'`, `'none'`, or a specific tool.

**Other**

- **`toolArgsGuard`** (`?ToolArgsGuard`, enabled) — a check of arguments for leaked call markup. `null` — don't check.
- **`exposeToolExceptions`** (`bool`, `false`) — whether to show the model the message of an exception thrown by a tool (trimmed to 300 characters). By default the model sees a neutral text and the details go to the log.
- **`limitNudgeMessage`** (`string`) — the message added to the history when `maxToolCalls` runs out.
- **`limitFallbackText`** (`string`) — the answer if the model stayed silent after that message.
- **`turnsExhaustedText`** (`string`) — the answer when `maxTurns` runs out.

All fields are public — set them directly:

```php
use Hameleon2x\Llm\Agent\Dto\Config;

$config = new Config();
$config->model = 'glm-4.6';
$config->maxTurns = 16;
$config->maxToolCalls = 12;
$config->params->temperature = 0.2;
$config->params->maxTokens = 8000;
```

## `model` and switching

The key is looked up in the catalog. On failure the runner retries the call, then moves on to the next model in the chain; with `stickyFallback = true` the remaining turns run on the model that answered — there's no point going back to the one that failed.

## `maxTurns` — what a turn is

One turn is one request to the model:

1. Build the system prompt.
2. Call the model once.
3. If there are no tool calls — return success.
4. Otherwise execute all calls of the turn and start the next one.

Several tool calls in one turn count as **one** turn, but as several units of `maxToolCalls`.

## `maxToolCalls` and the nudge

The counter decreases on every executed call across all turns. When it hits zero mid-turn:

1. The remaining calls of that turn are closed with an error — the history stays valid (every call has an answer).
2. `Message::user($config->limitNudgeMessage)` is added to the history.
3. One more request is made **without** tools.
4. A non-empty answer is returned as success, otherwise `limitFallbackText` is returned.

The result is marked `Finish::TOOL_LIMIT`, and the nudge's token spend is included in `Result::$usage`. If that follow-up request itself fails, the run returns an error with a category and `Finish::ERROR` — no placeholder is substituted for it.

## `deadlineSeconds`

Checked before every turn. On expiry the run returns a `Result` with an error of category `deadline`, `finish = Finish::DEADLINE`, and the full history — accumulated tool results are not lost.

## `params` and `extraParams`

`params` overrides the model's and catalog's parameters (merged by explicitness), and the model's `unsupported` strips out what it doesn't accept, on top of everything. `extraParams` merges with the provider's and model's fields and goes into **every** request of the run, including the limit nudge:

```php
$config->extraParams = [
    'session_id' => 'agent_42_run_17',   // groups the run in the provider's observability
];
```

Standard fields (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `stream`) are not overridden through `extraParams`.

OpenRouter plugins are a special case of extra fields:

```php
$config->extraParams['plugins'] = [
    ['id' => 'web', 'max_results' => 5],
];
```

## `toolChoice`

Passed through as-is to the OpenAI-compatible `tool_choice` parameter.

```php
$config->toolChoice = 'auto';     // the model decides
$config->toolChoice = 'required'; // the model must call a tool
$config->toolChoice = 'none';     // tools are visible but cannot be called
$config->toolChoice = ['type' => 'function', 'function' => ['name' => 'get_weather']];
```

## `toolArgsGuard`

Checks arguments before executing a tool and rejects the call if call-format markup leaked into the values. Enabled by default: a missed leak means executing on incomplete data, while a false positive costs one resent call.

```php
$config->toolArgsGuard = ToolArgsGuard::default(['~<my_tag~']);  // plus your own patterns
$config->toolArgsGuard = null;                                    // disable
```

## What a run returns

```php
$result->success;        // bool
$result->content;        // ?string
$result->error;          // ?ErrorInfo — the failure category
$result->finish;         // Finish::COMPLETED | TOOL_LIMIT | TURNS_EXHAUSTED | DEADLINE | ERROR | SUSPENDED
$result->messages;       // Message[] — the full history without the system message
$result->turnsUsed;
$result->toolCallsUsed;
$result->usage;          // tokens, cost, per-model breakdown
$result->modelKey;       // the model that worked last
$result->attempts;       // AttemptLog[] — attempts, retries, switches
$result->lastResponse;   // ?Response — extra, raw, finishReason of the last turn
$result->suspended;      // the run is paused, waiting for external input
$result->pendingToolCallIds;  // ids of the calls whose results must be provided
```

## See also

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — a walkthrough of the agent loop.
- [09-usage-and-limits.md](09-usage-and-limits.md) — token counters and cost.
- [10-error-handling.md](10-error-handling.md) — error categories and retries.
