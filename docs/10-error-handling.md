**Language:** **English** · [Русский](ru/10-error-handling.md)

# Error handling

How failures propagate through the stack, when retries happen, and what your code has to check.

## Exception hierarchy

All package exceptions extend [`LlmException`](../src/Exception/LlmException.php), which carries a `$retryable` flag:

| Class                       | Typical cause                                  | `code` | `retryable` |
|-----------------------------|------------------------------------------------|--------|-------------|
| `LlmException`              | base                                           | —      | flag        |
| `LlmProviderException`      | timeout, 5xx, malformed JSON                   | varies | `true`*     |
| `LlmRateLimitException`     | HTTP 429                                       | `429`  | `true`      |
| `LlmValidationException`    | HTTP 400, 401, 403, 404 …                      | code   | `false`     |

\* `LlmProviderException` is constructed with `retryable=true` by default; `OpenAiProvider` sets it to `false` for `cURL error 56` (receive failure from peer), since those have proved fatal in practice.

## What Client and Runner promise

[`Client::execute()`](../src/Client.php) **does not throw**. It catches `LlmException` and any `Throwable` from every provider, logs it (PSR-3), and either moves on to the next provider in the fallback chain, or returns a [`Response`](../src/Dto/Response.php) built via `Response::error(...)` if every provider failed.

[`Runner::run()`](../src/Agent/Runner.php) also **does not throw**. If `Client::execute()` returns a non-success response on any turn, `Runner` stops and returns `Result::error(...)`. Anything that escapes `ToolboxInterface::execute()` bubbles up through `Runner` as a regular PHP exception — that is on you.

Caller code only needs `if (!$response->isSuccess())` / `if (!$result->success)`.

## How the fallback chain reacts

For each provider in priority order, `Client`:

1. calls `$provider->execute($request)` (already wraps `BaseProvider`'s retry loop);
2. on success — returns the response;
3. on unsuccessful `Response` — logs `warning`, remembers it, tries the next provider;
4. on `LlmException` — logs `warning`, tries the next provider;
5. on any other `Throwable` — logs `error` with the stack trace, tries the next provider.

When the loop ends with no success, `Client` returns the last unsuccessful response (or a synthetic `Status::ERROR` if every provider threw).

## Provider retry loop

[`BaseProvider::execute()`](../src/Provider/BaseProvider.php) wraps `doExecute()` in a retry loop driven by `$retryAttempts` (default `3`): on retryable `LlmException` — sleep, then retry; on non-retryable — give up immediately. Backoff is **exponential with a 10-second cap**: 1s → 2s → 4s → 8s → 10s → 10s → …

After all attempts are spent, `BaseProvider` maps the last exception to a `Status` via `getStatusFromException()` (`RateLimit*` → `RATE_LIMIT`, `Validation*` → `VALIDATION_ERROR`, `Provider*` → `PROVIDER_ERROR`, `Timeout*` → `TIMEOUT`, otherwise `ERROR`).

## Response statuses

[`Status`](../src/Enum/Status.php) values that can appear on a returned `Response`:

| Constant            | Value                | When                                                |
|---------------------|----------------------|-----------------------------------------------------|
| `SUCCESS`           | `'success'`          | request completed normally                          |
| `PROVIDER_ERROR`    | `'provider_error'`   | 5xx, malformed response, generic network failure    |
| `RATE_LIMIT`        | `'rate_limit'`       | HTTP 429                                            |
| `VALIDATION_ERROR`  | `'validation_error'` | HTTP 4xx (except 429)                               |
| `TIMEOUT`           | `'timeout'`          | reserved for timeout-shaped exceptions              |
| `ERROR`             | `'error'`            | catch-all                                           |

## Inspecting a failed response

```php
<?php
use Hameleon2x\Llm\Enum\Status;

/** @var \Hameleon2x\Llm\Dto\Response $response */
if ($response->isSuccess()) {
    echo $response->content;
    return;
}

switch ($response->status) {
    case Status::RATE_LIMIT:        // back off; user-facing "try again later"
    case Status::VALIDATION_ERROR:  // bug in our request — do not retry, raise an alert
    case Status::PROVIDER_ERROR:
    case Status::TIMEOUT:
    case Status::ERROR:
    default:
        // every provider failed in the fallback chain
}

$errorMessage = $response->error;
$rootCause    = $response->exception;  // ?Throwable, original exception if any
$provider     = $response->provider;   // name of the provider that surfaced the error
```

For agent runs, the same status is condensed into `Result::$error` (string). `Result` does not carry the underlying `Response::$exception` — log it from your provider configuration if you need it.

## Tool execution errors

Tool failures are **not** exceptions in this design. Return `Tool\Dto\Result::error('...')` and `Runner` will serialise it to a `tool` message as `{"error": "..."}`. The model sees it on the next turn and can react. Throwing inside `Toolbox::execute()` bubbles out of `Runner::run()` unchanged.

## See also

- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — provider priority and fallback semantics.
- [docs/03-logging.md](03-logging.md) — PSR-3 messages emitted by `Client` and `BaseProvider`.
- [docs/12-custom-provider.md](12-custom-provider.md) — which exception to throw from your own `doExecute()`.
