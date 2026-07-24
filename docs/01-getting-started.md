**Language:** **English** · [Русский](ru/01-getting-started.md)

# Getting started

The shortest path from `composer require` to a working LLM call.

## Installation

```bash
composer require hameleon2x/llm-orchestra
```

Requirements: PHP 7.4+, `ext-curl`, `ext-json`, `psr/log`.

## Catalog and executor

Two entry points: `Registry` is the catalog of providers and models, `Orchestra` executes requests against the catalog.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;

$registry = Registry::fromArray([
    'providers' => [
        'openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...'],
    ],
    'models' => [
        'mini' => ['provider' => 'openai', 'name' => 'gpt-4o-mini'],
    ],
    'defaultModel' => 'mini',
]);

$orchestra = new Orchestra($registry);
```

There is exactly as much required here as you see: a provider knows where to call and how to authenticate, a model knows which provider to go through and the slug the API knows it by. Everything else — generation parameters, retry policy, fallback chain — is optional and gets added as needed (see [02-catalog-and-fallback.md](02-catalog-and-fallback.md)).

The catalog is validated as a whole at build time: a reference to a nonexistent provider, a typo in the fallback chain, or a duplicate alias raises `LlmConfigException` immediately, not at the moment of a production failure.

## Sending a request

`Request::simple($system, $user)` is the shortest constructor. For an arbitrary history use `Request::messages($messages)`, for tool calling use `Request::withTools(...)` (usually through [`Agent\Runner`](05-toolbox-and-runner.md)).

```php
<?php
use Hameleon2x\Llm\Dto\Request;

$response = $orchestra->execute(Request::simple(
    'You are a concise assistant.',
    'Explain what PHP is in one sentence.'
));
```

The second argument of `execute()` is a model key from the catalog. If omitted, `defaultModel` is used:

```php
$response = $orchestra->execute($request, 'mini');
```

## Reading the response

Success means the absence of an error. On failure `content` is `null`, and `error` holds a parsed error with a category.

```php
<?php
if ($response->isSuccess()) {
    echo $response->content;
} else {
    fwrite(STDERR, "LLM failed: {$response->error->category} — {$response->error->message}\n");
}
```

### What else is in the response

```php
$response->content;        // answer text; null on failure or when only tool calls came back
$response->toolCalls;      // ToolCall[] — what the model asked to call
$response->usage;          // tokens, cache, reasoning, cost
$response->modelKey;       // catalog key of the model that answered
$response->modelName;      // its slug for the API
$response->providerKey;    // which transport the request went through
$response->attempts;       // attempt log: retries and model switches
$response->error;          // ErrorInfo with the failure category; null on success

$response->extra('reasoning');                  // model's reasoning, if it returned any
$response->raw('choices.0.finish_reason');      // any field of the raw response by path
$response->finishReason();                      // stop, length, tool_calls…
$response->isTruncated();                       // response truncated by the token limit
```

Remember `modelKey`: if a failure triggers a switch to a backup model, the answer comes from a different model than the one you requested, and this is the only place you can see it. Details — [10-error-handling.md](10-error-handling.md) and [09-usage-and-limits.md](09-usage-and-limits.md).

## Generation parameters

They are set at three levels and merge by explicitness: catalog → model → call.

```php
$request = Request::simple($system, $user)
    ->setTemperature(0.2)
    ->setMaxTokens(2000);
```

The same thing, but for every request of the model — in the catalog:

```php
'models' => [
    'mini' => [
        'provider' => 'openai',
        'name'     => 'gpt-4o-mini',
        'params'   => ['temperature' => 0.2, 'maxTokens' => 2000],
    ],
],
```

## Provider-specific payload fields

Anything without a dedicated parameter is set as extra payload fields — at the provider, model, or call level:

```php
$request->setExtraParams(['session_id' => 'run_42']);
```

Standard fields (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`) cannot be overwritten through `extraParams` — use the generation parameters for those.

## Next

- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — the full catalog: retry policy, fallback chain, model modes.
- [10-error-handling.md](10-error-handling.md) — error categories and what to do about them.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the agent loop with tools.
