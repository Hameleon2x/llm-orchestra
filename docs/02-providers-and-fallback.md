**Language:** **English** · [Русский](ru/02-providers-and-fallback.md)

# Providers and fallback

How to register multiple LLM providers, control the order `Client` tries them in, and which errors are retried vs. trigger a fallthrough.

## Built-in providers

All three providers speak the same OpenAI-compatible Chat Completions API and inherit from `OpenAiProvider` → `BaseProvider`. They differ only in default `baseUrl`, default `model`, and `getName()`.

| Class                                          | Default `baseUrl`            | Default `model`                       | `getName()`  |
|------------------------------------------------|------------------------------|---------------------------------------|--------------|
| `Hameleon2x\Llm\Provider\OpenAiProvider`       | `https://api.openai.com`     | `gpt-4o-mini`                         | `OpenAI`     |
| `Hameleon2x\Llm\Provider\OpenRouterProvider`   | `https://openrouter.ai/api`  | `deepseek/deepseek-chat-v3-0324:free` | `OpenRouter` |
| `Hameleon2x\Llm\Provider\RequestyProvider`     | `https://router.requesty.ai` | `openai/gpt-4.1-mini`                 | `Requesty`   |

The `/v1/chat/completions` suffix is appended by `CurlChatClient` — pass `baseUrl` without `/v1`.

## Config keys

Each entry in `$client->providers` is either a `ProviderInterface` instance or an array config consumed by `Client::createProvider()`:

| Key               | Type           | Default                              | Purpose                                                                |
|-------------------|----------------|--------------------------------------|------------------------------------------------------------------------|
| `class`           | class-string   | required                             | Provider class to instantiate.                                         |
| `token`           | string         | required                             | API token.                                                             |
| `model`           | string         | required                             | Default model for this provider.                                       |
| `baseUrl`         | ?string        | provider-specific                    | Override upstream URL (proxies, self-hosted gateways).                 |
| `temperature`     | ?float         | `Client::$defaultTemperature` (0.7)  | Generation temperature.                                                |
| `topP`            | ?float         | `Client::$defaultTopP` (`null`)      | Top-p sampling. When unset, `top_p` is not sent — some providers (e.g. Anthropic) reject it together with `temperature`. |
| `maxTokens`       | ?int           | `Client::$defaultMaxTokens` (1024)   | Response token cap.                                                    |
| `retryAttempts`   | int            | 3                                    | How many times to retry retryable errors.                              |
| `timeout`         | int            | 30                                   | HTTP timeout (seconds).                                                |
| `priority`        | int            | 999                                  | Lower number = tried first.                                            |
| `supportedModels` | ?string[]      | `null`                               | Substrings; if a request's `model` matches none, provider is skipped.  |

A directly constructed provider (`new OpenAiProvider(...)`) receives the same parameters in the same order via its constructor.

## Fallback order: `priority`

`Client::execute()` sorts providers ascending by `priority` and tries them in order. The first to return `Response::isSuccess() === true` wins. A provider is skipped and the next one tried when:

1. It throws a non-retryable `LlmException`, or exhausts its `retryAttempts`.
2. It returns a `Response` with `status !== SUCCESS`.
3. It throws any other `Throwable` (logged at `error` level).

```php
<?php
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Provider\OpenRouterProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => 'sk-or-...', 'model' => 'anthropic/claude-3.5-sonnet', 'priority' => 1], // primary
    ['class' => OpenAiProvider::class,     'token' => 'sk-...',     'model' => 'gpt-4o-mini',                'priority' => 2], // backup
];

$response = $client->execute(Request::simple('You are concise.', 'Hi.'));
echo $response->provider; // 'OpenRouter' or 'OpenAI' depending on which one answered
```

If every provider fails, the last failing `Response` is returned (so `status`/`error` reflect the final attempt). If the list is empty, you get a synthetic `Response::error(Status::ERROR, 'all', 'none', ...)`.

## Retries within a provider

`BaseProvider::execute()` wraps each request in a retry loop:

- Up to `retryAttempts` attempts (default 3).
- Exponential backoff between attempts: 1s, 2s, 4s, 8s, capped at 10s.
- Only retryable errors are retried. Non-retryable ones break out immediately so `Client` can fall through to the next provider.

Retryability is encoded on the exception:

| Exception                  | Retryable | Triggers                                                                |
|----------------------------|-----------|-------------------------------------------------------------------------|
| `LlmRateLimitException`    | yes       | HTTP 429, or error payload with `code === 429`.                          |
| `LlmProviderException`     | yes (default) | Network errors, 5xx, malformed JSON, empty responses. cURL error 56 is marked non-retryable. |
| `LlmValidationException`   | no        | HTTP 4xx other than 429; or `model` not in `supportedModels`.            |

After the loop, the provider returns `Response::error(...)` with a `status` derived from the last exception class (`RATE_LIMIT`, `VALIDATION_ERROR`, `PROVIDER_ERROR`, `TIMEOUT`, `ERROR`).

## Skipping providers by model: `supportedModels`

When a request pins a specific `model` via `Request::setModel('...')`, each provider checks the name against `supportedModels` (substring match). A miss raises `LlmValidationException` (non-retryable), so `Client` immediately moves to the next provider.

```php
$client->providers = [
    [
        'class'           => OpenAiProvider::class,
        'token'           => 'sk-...',
        'model'           => 'gpt-4o-mini',
        'supportedModels' => ['gpt-', 'o1-', 'o3-'],
        'priority'        => 1,
    ],
    [
        'class'           => OpenRouterProvider::class,
        'token'           => 'sk-or-...',
        'model'           => 'anthropic/claude-3.5-sonnet',
        'supportedModels' => null, // accept anything
        'priority'        => 2,
    ],
];

// Asking for a Claude model — OpenAI is skipped, OpenRouter handles it.
$request = Request::simple('be brief', 'hi')->setModel('anthropic/claude-3.5-sonnet');
$response = $client->execute($request);
```

`supportedModels = null` (the default) means "accept everything".

## Timeout

`timeout` is the cURL request timeout in seconds; connect timeout is `min(30, $timeout)`. A blown timeout surfaces as `LlmProviderException` (retryable) inside the provider's loop.

## See also

- [01-getting-started.md](01-getting-started.md) — single-provider quickstart.
- [03-logging.md](03-logging.md) — observe retries and fallbacks via PSR-3.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — how `Runner` uses `Client`.
