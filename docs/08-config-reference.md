**Language:** **English** · [Русский](ru/08-config-reference.md)

# Agent config reference

Full reference for [`Agent\Dto\Config`](../src/Agent/Dto/Config.php) — the parameter bag for a single `Runner::run()` call.

## Fields

| Field                  | Type            | Default                                                              | Description                                                                 |
|------------------------|-----------------|----------------------------------------------------------------------|-----------------------------------------------------------------------------|
| `maxTurns`             | `int`           | `10`                                                                 | Hard limit on agent loop iterations (one LLM request = one turn).           |
| `maxToolCalls`         | `int`           | `30`                                                                 | Cumulative cap on tool calls for the whole run (not per turn).              |
| `temperature`          | `?float`        | `null`                                                               | If `null`, the provider default is used.                                    |
| `maxTokens`            | `?int`          | `null`                                                               | If `null`, the provider default is used.                                    |
| `toolChoice`           | `string\|array` | `'auto'`                                                             | `'auto'`, `'required'`, `'none'`, or a forced function (array, see below).  |
| `plugins`              | `?array`        | `null`                                                               | OpenRouter-specific plugin payload (e.g. web search). `null` — no plugins.  |
| `extraParams`          | `?array`        | `null`                                                               | Extra payload fields merged into every request of the run (e.g. `session_id`). See below. |
| `limitNudgeMessage`    | `string`        | `'Лимит обращений к инструментам исчерпан. Дай итоговый ответ ...'`  | User message appended when `maxToolCalls` is hit (see below).               |
| `limitFallbackText`    | `string`        | `'Не удалось завершить за допустимое число вызовов инструментов.'`   | Fallback answer when the nudge request returns nothing.                     |
| `turnsExhaustedText`   | `string`        | `'Не удалось завершить за допустимое число итераций.'`               | Final answer when `maxTurns` is hit.                                        |

All fields are public — set them directly, no setters or constructor:

```php
use Hameleon2x\Llm\Agent\Dto\Config;

$config = new Config();
$config->maxTurns     = 6;
$config->maxToolCalls = 12;
$config->temperature  = 0.2;
```

## `maxTurns` — what is a turn

One turn is one LLM request. The loop:

1. Compose the system prompt.
2. Call `Client::execute()` once.
3. If the response has no tool calls — return success.
4. Otherwise execute every tool call from the response and start the next turn.

Multiple tool calls produced inside the same assistant message count as **one** turn but consume several entries from `maxToolCalls`.

## `maxToolCalls` and the nudge

`maxToolCalls` is decremented per executed tool call across all turns. When it hits zero mid-turn, `Runner` enters the limit-finish path:

1. Append `Message::user(limitNudgeMessage)` to the history.
2. Send one more request **without** tools (no `tools` / no `tool_choice`).
3. If the model returns a non-empty answer, return it as a success result.
4. Otherwise return `limitFallbackText` as a success result.

The token usage of that extra call is added to `Result::$usage` — see [docs/09-usage-and-limits.md](09-usage-and-limits.md).

## `turnsExhaustedText`

If `maxTurns` is reached without a terminating answer, `Runner` returns a success `Result` whose `content` is `turnsExhaustedText`. The full history (including the last tool results) is preserved in `$result->messages`.

## `temperature` and `maxTokens`

Both are optional overrides. If left `null`, the provider falls back to its constructor argument, and then to `Client::$defaultTemperature` / `Client::$defaultMaxTokens`. `topP` cannot be overridden per run — set it on the client or provider.

## `toolChoice`

Pass-through to the OpenAI-compatible `tool_choice` parameter.

```php
$config->toolChoice = 'auto';     // model decides
$config->toolChoice = 'required'; // model MUST call a tool on the next turn
$config->toolChoice = 'none';     // tools are listed but cannot be called

// Force a specific function:
$config->toolChoice = [
    'type'     => 'function',
    'function' => ['name' => 'get_weather'],
];
```

The forced-function form is sent verbatim — keep the shape compatible with your provider's API.

## `plugins` (OpenRouter)

OpenRouter exposes server-side plugins (web search, etc.) via the `plugins` request field. Example for web search:

```php
$config->plugins = [
    [
        'id'            => 'web',
        'max_results'   => 5,
        'search_prompt' => 'Search the web for recent information about the user question.',
    ],
];
```

`plugins` is honoured only when the chosen provider accepts the field. For plain OpenAI it is ignored.

## `extraParams` — provider-specific payload fields

Universal escape hatch for fields that the OpenAI-compatible providers accept but the library does not expose as a dedicated `Config` setter — `session_id` on OpenRouter (groups requests in observability; max 256 chars), `user` on OpenAI (end-user identifier), `response_format`, etc.

```php
$config->extraParams = [
    'session_id' => 'agent_42_run_17',
];
```

These fields are merged into the payload of **every** request the `Runner` makes during the run (initial turns and the limit-finish nudge), so all calls inside one agent run share the same session group. Standard keys (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) always win and cannot be overridden through `extraParams`.

See also [docs/01-getting-started.md](01-getting-started.md#provider-specific-payload-fields) for the request-level `Request::setExtraParams()` equivalent when calling `Client::execute()` directly without the agent loop.

## See also

- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — full `Runner` walk-through.
- [docs/09-usage-and-limits.md](09-usage-and-limits.md) — what the limit counters look like in `Result::$usage`.
- [docs/10-error-handling.md](10-error-handling.md) — how `Runner` reports errors when limits are not the problem.
