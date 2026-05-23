**Language:** **English** · [Русский](ru/01-getting-started.md)

# Getting started

The minimum path from `composer require` to a working LLM call.

## Install

```bash
composer require hameleon2x/llm-orchestra
```

Requirements: PHP 7.4+, `ext-curl`, `ext-json`, `psr/log`.

## Create a client

`Client` is the entry point. Providers are listed in `$client->providers` as either fully constructed `ProviderInterface` instances or, more commonly, array configs that are lazily instantiated.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Provider\OpenAiProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];
```

`class`, `token`, `model` are required; everything else inherits sane defaults — see [02-providers-and-fallback.md](02-providers-and-fallback.md).

## Send a request

`Request::simple($system, $user)` is the shortest constructor — one system + one user message. For arbitrary history use `Request::messages($messages)`; for tool calling use `Request::withTools(...)` (typically driven by `Agent\Runner`, see [05-toolbox-and-runner.md](05-toolbox-and-runner.md)).

```php
<?php
use Hameleon2x\Llm\Dto\Request;

$response = $client->execute(Request::simple(
    'You are a helpful assistant.',
    'Explain what PHP is in one sentence.'
));
```

## Read the response

Always check `isSuccess()` before reading `content` — on failure `content` is `null` and `error` carries the message.

```php
<?php
if ($response->isSuccess()) {
    echo $response->content;
} else {
    fwrite(STDERR, "LLM failed: {$response->error}\n");
}
```

### `Response` surface

| Property / method                                                              | Meaning                                                                |
|--------------------------------------------------------------------------------|------------------------------------------------------------------------|
| `$response->status`                                                            | Constant from `Hameleon2x\Llm\Enum\Status`: `SUCCESS`, `RATE_LIMIT`, `PROVIDER_ERROR`, `VALIDATION_ERROR`, `TIMEOUT`, `ERROR`. |
| `$response->isSuccess()`                                                       | Shortcut for `$status === Status::SUCCESS`.                            |
| `$response->content`                                                           | Assistant text. `null` on failure or when only tool calls were returned. |
| `$response->toolCalls`, `$response->hasToolCalls()`                            | `ToolCall[]` from the model.                                           |
| `$response->provider`, `$response->model`                                      | Which provider/model actually answered.                                |
| `$response->error`                                                             | Error string when `status !== SUCCESS`.                                |
| `getPromptTokens()`, `getCompletionTokens()`, `getTotalTokens()`               | Token counts from the provider's `usage` block.                        |
| `getLatency()`                                                                 | Wall-clock seconds inside the provider call.                           |
| `$response->metadata`                                                          | Raw map: `promptTokens`, `completionTokens`, `totalTokens`, `finishReason`, `latency`, `attempts`. |

## A second provider: OpenRouter

OpenRouter and Requesty are drop-in replacements — same OpenAI-compatible API, different base URLs and model catalogs. The provider class wires the correct default `baseUrl` for you; override it only when you proxy the API.

```php
<?php
use Hameleon2x\Llm\Provider\OpenRouterProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => 'sk-or-...', 'model' => 'anthropic/claude-3.5-sonnet'],

    // To use a proxy / self-hosted gateway, add:
    // 'baseUrl' => 'https://my-proxy.example.com/openrouter',
];

$response = $client->execute(Request::simple('You are concise.', 'Name 3 PHP frameworks.'));
echo $response->content;
```

## See also

- [02-providers-and-fallback.md](02-providers-and-fallback.md) — multiple providers, fallback order, retries.
- [03-logging.md](03-logging.md) — capture retry and fallback events.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — multi-turn dialogs with tool calling.
