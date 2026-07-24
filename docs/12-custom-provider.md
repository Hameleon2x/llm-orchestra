**Language:** **English** · [Русский](ru/12-custom-provider.md)

# Custom provider

A provider is responsible for the API format: assemble the payload, send it, and parse the response. Everything else — merging settings, retries, model switching, the attempt log — is done by `Orchestra`, so a custom provider usually takes just a few dozen lines.

## When you need one

- The API isn't OpenAI-compatible (its own message or tool format).
- The response needs different parsing.
- A local model with its own protocol.

If the API is OpenAI-compatible and only the address differs, you don't need to write a provider: `baseUrl` in the config is enough. If the headers or extra payload fields differ, that's covered too: use `headers` and `extraParams` (see [02-catalog-and-fallback.md](02-catalog-and-fallback.md)).

## Contract

```php
interface ProviderInterface
{
    public function execute(ResolvedCall $call): Response;   // throws LlmException
    public function key(): string;                           // provider key in the catalog
    public function name(): string;                          // name for logs
}
```

The catalog creates a provider as `new $class($definition, $logger)` — a constructor with that signature is mandatory. The easiest way is to extend `BaseProvider`: it provides the constructor, access to settings, the HTTP client, and applying the `capture` map.

## What arrives in `ResolvedCall`

The merging of the three configuration levels is already done — the provider just takes the result:

```php
$call->request;            // messages, tools, toolChoice
$call->modelName();        // model slug for the API
$call->modelKey();         // catalog model key — put it into Response
$call->providerKey();      // catalog provider key
$call->paramsPayload();    // temperature, top_p, max_tokens, seed — without whatever the model doesn't support
$call->extraParams;        // extra payload fields: provider + model + call
$call->headers;            // headers: provider + model + call
$call->timeout;            // request timeout, seconds
$call->capture;            // response field extraction map
$call->keepRaw;            // whether to put the raw response into Response
```

## A minimal provider

```php
<?php

use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Dto\Usage;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Factory\MessageFactory;
use Hameleon2x\Llm\Provider\BaseProvider;
use Hameleon2x\Llm\Support\ArrayPath;

final class MyProvider extends BaseProvider
{
    public function name(): string
    {
        return 'MyProvider';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /** Response fields the application will get via $response->extra(). */
    protected function defaultCapture(): array
    {
        return ['reasoning' => 'result.thinking'];
    }

    public function execute(ResolvedCall $call): Response
    {
        $payload = $call->extraParams + [
            'model'    => $call->modelName(),
            'messages' => array_map(
                static fn($message) => MessageFactory::toArray($message),
                $call->request->messages
            ),
        ];
        $payload += $call->paramsPayload();

        $startedAt = microtime(true);
        $body = $this->client()->chat($payload, $call->headers, $call->timeout);
        $latency = microtime(true) - $startedAt;

        $raw = json_decode($body, true);
        if (!is_array($raw)) {
            throw new LlmException(new ErrorInfo(ErrorCategory::INVALID_RESPONSE, 'Response is not valid JSON.'));
        }

        $content = (string)ArrayPath::get($raw, 'result.text', '');
        if (trim($content) === '') {
            throw new LlmException(new ErrorInfo(ErrorCategory::EMPTY_RESPONSE, 'The model returned nothing.'));
        }

        $usage = new Usage();
        $usage->calls = 1;
        $usage->promptTokens = (int)ArrayPath::get($raw, 'result.tokens.in', 0);
        $usage->completionTokens = (int)ArrayPath::get($raw, 'result.tokens.out', 0);
        $usage->totalTokens = $usage->promptTokens + $usage->completionTokens;

        $response = new Response();
        $response->content = $content;
        $response->usage = $usage;
        $response->modelKey = $call->modelKey();
        $response->modelName = $call->modelName();
        $response->providerKey = $call->providerKey();
        $response->extra = $this->capture($raw, $call);
        $response->metadata = ['latency' => $latency];
        $response->setRaw($call->keepRaw ? $raw : null);

        return $response;
    }
}
```

Registration is the same as for any other transport:

```php
'providers' => [
    'mine' => ['class' => MyProvider::class, 'token' => '...'],
],
'models' => [
    'my-model' => ['provider' => 'mine', 'name' => 'model-v1'],
],
```

The catalog checks that the class exists and implements `ProviderInterface` already at build time.

## Errors

A provider throws `LlmException` with an `ErrorInfo`. The easiest way to get a category is from `ErrorMapper`, if the failure arrived over HTTP or as an exception:

```php
throw new LlmException(ErrorMapper::fromHttpStatus($status, $body, $decoded));
throw new LlmException(ErrorMapper::fromThrowable($e), $e);
throw LlmException::of(ErrorCategory::CONTENT_FILTER, 'Blocked by moderation.');
```

Important: **do not retry inside the provider**. There is exactly one retry level — the model policy in `Orchestra`; a private loop of your own would make waiting time unpredictable.

Classify at least three situations correctly, or retries and fallback will work blind:

- a transport failure and a timeout — `ErrorMapper::fromCurl()` or `fromThrowable()`;
- a response with no content — `EMPTY_RESPONSE`;
- a response that doesn't parse — `INVALID_RESPONSE`.

## Extending OpenAiProvider

If the API is OpenAI-compatible but parses a bit differently, extend `OpenAiProvider` and override selectively: `defaultBaseUrl()`, `defaultCapture()`, `buildPayload()`, `parse()`, `parseUsage()`, `parseToolCalls()`, `normalizeContent()`. That's how `OpenRouterProvider` and `RequestyProvider` are built — they differ only in the base URL and the name.

## See also

- [11-custom-http-client.md](11-custom-http-client.md) — when you need a different transport, not a different API format.
- [10-error-handling.md](10-error-handling.md) — error categories.
- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — how a provider is described in the catalog.
