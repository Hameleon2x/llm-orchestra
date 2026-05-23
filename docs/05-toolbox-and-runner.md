**Language:** **English** · [Русский](ru/05-toolbox-and-runner.md)

# Toolbox and Runner

`Agent\Runner` is the agent loop: call the model, execute any tools it asked for, append results to history, repeat until the model produces a final answer or hits a limit. The `Toolbox` is the registry the `Runner` consults. For the `ToolInterface` contract see [04-tools.md](04-tools.md).

## `AbstractToolbox`

`Hameleon2x\Llm\Agent\AbstractToolbox` is the default `ToolboxInterface`. Subclass it, implement `buildTools()`, optionally toggle `log_message`.

```php
<?php
use App\Llm\Tools\GetWeatherTool;
use Hameleon2x\Llm\Agent\AbstractToolbox;

final class MyToolbox extends AbstractToolbox
{
    // Optional: inject obligatory `log_message` into every tool's schema.
    protected bool    $withLogMessage        = true;
    protected ?string $logMessageDescription = 'Short note for the dialog UI: what you are doing and why.';

    // Called lazily once. Inject DI services into tool constructors here.
    protected function buildTools(): array
    {
        return [
            new GetWeatherTool(/* $someService, $repository, ... */),
            // ...
        ];
    }
}
```

`buildTools()` is the DI seam — tools usually need real services (HTTP clients, repositories, the current user, a clock).

### `$withLogMessage` / `$logMessageDescription`

When `$withLogMessage = true`, `SchemaBuilder` injects a mandatory string parameter named `log_message` into every tool's JSON Schema. The model is forced to include a short human-readable note with each call — great for chat UIs that want to render "Looking up the weather in Moscow..." without inferring it from the tool name and args.

The parameter name is fixed (`SchemaBuilder::LOG_MESSAGE_PARAM = 'log_message'`). The description defaults to the Russian text in `SchemaBuilder::LOG_MESSAGE_DESCRIPTION_DEFAULT`; override it via `$logMessageDescription` to match your prompt language. `log_message` is forwarded into `execute($args)` like any other argument — your tool can read or ignore it.

## `Runner::run()`

```php
public function run(
    array            $messages,        // Message[]   — dialog history, no system message
    ToolboxInterface $toolbox,
    callable         $systemPromptFn,  // fn(Message[] $history): string
    Config           $config,
    ?callable        $emit = null      // fn(string $event, string $content, array $meta): void
): Result
```

| Parameter         | Notes                                                                                                              |
|-------------------|--------------------------------------------------------------------------------------------------------------------|
| `$messages`       | `Message[]` without a `system` entry. The runner builds the system message every turn via `$systemPromptFn`.       |
| `$toolbox`        | Any `ToolboxInterface`. Definitions are read once; `execute()` is called per tool call.                            |
| `$systemPromptFn` | Called every turn with the current history. Return the base system prompt — `SystemPromptComposer` augments it.    |
| `$config`         | `Agent\Dto\Config` — limits, generation overrides, fallback texts (below).                                          |
| `$emit`           | Optional event sink — see [06-events.md](06-events.md).                                                             |

## `Config`

`Hameleon2x\Llm\Agent\Dto\Config` — knobs for one run:

| Field                | Type           | Default | Meaning                                                                                                  |
|----------------------|----------------|---------|----------------------------------------------------------------------------------------------------------|
| `maxTurns`           | `int`          | 10      | Hard cap on loop iterations (1 iteration = 1 LLM call + its tool execution).                              |
| `maxToolCalls`       | `int`          | 30      | Hard cap on total tool invocations across the run.                                                        |
| `temperature`        | `?float`       | `null`  | Overrides provider default; `null` = leave it alone.                                                      |
| `maxTokens`          | `?int`         | `null`  | Same for token cap.                                                                                       |
| `toolChoice`         | `string\|array`| `'auto'`| `'auto'`, `'required'`, `'none'`, or `['type' => 'function', 'function' => ['name' => 'foo']]`.            |
| `plugins`            | `?array`       | `null`  | OpenRouter plugins (e.g. web search) — passed straight through.                                          |
| `limitNudgeMessage`  | `string`       | …       | User message appended when `maxToolCalls` runs out, before the final LLM call.                            |
| `limitFallbackText`  | `string`       | …       | Used if that final LLM call returns nothing.                                                              |
| `turnsExhaustedText` | `string`       | …       | Returned as the assistant answer when `maxTurns` is reached.                                              |

## `Result`

`Hameleon2x\Llm\Agent\Dto\Result` — what `Runner::run()` returns:

| Property         | Type                | Meaning                                                                            |
|------------------|---------------------|------------------------------------------------------------------------------------|
| `$success`       | `bool`              | `false` only when the LLM call itself failed (`Response::isSuccess() === false`).  |
| `$content`       | `?string`           | Final assistant text on success. `null` on failure.                                |
| `$error`         | `?string`           | Error message on failure.                                                          |
| `$messages`      | `Message[]`         | Full dialog after the run (no system message). Persist this if you continue later. |
| `$turnsUsed`     | `int`               | Iterations consumed (1..`maxTurns`).                                               |
| `$toolCallsUsed` | `int`               | Tool invocations consumed (0..`maxToolCalls`).                                     |
| `$usage`         | `Agent\Dto\Usage`   | `llmCalls`, `promptTokens`, `completionTokens`, `totalTokens` across the run.       |

Hitting `maxTurns` or `maxToolCalls` produces `success = true` with one of the configured fallback texts as `$content` — it's not an error, the run completed gracefully. Inspect `$turnsUsed` / `$toolCallsUsed` to detect saturation.

## How the system prompt grows: `SystemPromptComposer`

Every turn the runner calls `$systemPromptFn($messages)` to get the base prompt, then hands it to `Agent\SystemPromptComposer::compose()`. The composer:

1. Scans `$messages` for assistant turns that carried `tool_calls`.
2. Collects unique tool names used (sorted alphabetically).
3. Calls `$toolbox->systemPromptAddition($name)`, which delegates to `ToolInterface::appendToSystemPromptAfterUse()`.
4. Appends non-empty notes under the header `"Дополнительные пояснения по уже использованным инструментам:"` (Russian — change `SystemPromptComposer::TOOL_NOTES_HEADER` if you fork the package).

Net effect: tools that were never called contribute nothing; tools that have been called add their output-schema notes from the next turn onward.

## When limits run out

- **`maxToolCalls` exhausted mid-turn.** `Runner::finishOnToolLimit()` appends `Config::$limitNudgeMessage` as a `user` message, then makes one final LLM call **without tools**. If the model responds, that becomes the answer; otherwise `Config::$limitFallbackText`. Either way `success = true`.
- **`maxTurns` reached.** The runner appends `Config::$turnsExhaustedText` as the assistant message and returns `success = true`.

## Full example

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Llm\Tools\GetWeatherTool;
use App\Llm\Tools\TimeNowTool;
use Hameleon2x\Llm\Agent\AbstractToolbox;
use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Provider\OpenAiProvider;

final class WeatherToolbox extends AbstractToolbox
{
    protected bool $withLogMessage = true;
    protected function buildTools(): array
    {
        return [new GetWeatherTool(), new TimeNowTool()];
    }
}

$client = new Client();
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];

$config = new Config();
$config->maxTurns = 5;
$config->maxToolCalls = 10;
$config->temperature = 0.3;

$result = (new Runner($client))->run(
    [Message::user('What is the weather in Moscow right now?')],
    new WeatherToolbox(),
    static fn(array $history): string => 'You are a concise weather assistant. Use tools when you need facts.',
    $config
);

echo $result->success ? $result->content : "Run failed: {$result->error}";
printf(
    "\nturns=%d toolCalls=%d llmCalls=%d tokens=%d\n",
    $result->turnsUsed, $result->toolCallsUsed,
    $result->usage->llmCalls, $result->usage->totalTokens
);
```

## See also

- [04-tools.md](04-tools.md) — `ToolInterface` contract.
- [06-events.md](06-events.md) — `$emit` callback for in-loop progress.
- [02-providers-and-fallback.md](02-providers-and-fallback.md) — how the underlying `Client` chooses a provider.
- [03-logging.md](03-logging.md) — the separate PSR-3 channel for retries/fallbacks.
