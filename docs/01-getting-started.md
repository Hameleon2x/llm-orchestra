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

## Provider-specific payload fields

Some providers accept extra payload fields outside the OpenAI-compatible core — for example, OpenRouter understands `session_id` (groups related requests in their dashboard for conversation/agent observability; max 256 chars). OpenAI itself accepts `user` (end-user identifier for abuse tracking). The library does not have a dedicated setter for every such field; instead pass them through `setExtraParams()`:

```php
<?php
$request = Request::simple('You are concise.', 'Summarize PHP in one line.')
    ->setExtraParams([
        'session_id' => 'agent_42_run_17', // OpenRouter — groups requests under one session
        // 'user' => 'user-1234',          // OpenAI — end-user identifier
    ]);

$response = $client->execute($request);
```

`extraParams` are merged into the payload by `OpenAiProvider`. Standard keys (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) always win — you cannot override them this way. Unknown fields a given provider does not understand are typically ignored on the server side; check the target provider's docs before relying on a specific key.

## See also

- [02-providers-and-fallback.md](02-providers-and-fallback.md) — multiple providers, fallback order, retries.
- [03-logging.md](03-logging.md) — capture retry and fallback events.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — multi-turn dialogs with tool calling.
