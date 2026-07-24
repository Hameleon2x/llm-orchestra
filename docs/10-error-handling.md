**Language:** **English** · [Русский](ru/10-error-handling.md)

# Errors, retries, and backup models

The network drops, a provider answers with 429, a model stays silent. The library takes care of retries and switching to a backup model, and hands you back a result that shows exactly what happened.

## Checking for an error

Neither `Orchestra` nor `Runner` throws exceptions. Success means the absence of an error:

```php
$response = $orchestra->execute(Request::simple('Answer briefly.', 'What is PHP?'));

if ($response->isSuccess()) {
    echo $response->content;
} else {
    echo 'Failed: ' . $response->error->category;   // e.g., timeout
}
```

The main rule: **never parse the error text**. Provider wording changes without notice, so every failure is mapped to a category, and decisions are made from that category:

```php
use Hameleon2x\Llm\Error\ErrorCategory;

$error = $response->error;

if ($error->isConnectionDrop()) {
    // network, timeout, or an empty turn — no data lost, safe to retry later
}

if ($error->is(ErrorCategory::RATE_LIMIT)) {
    // the provider is asking you to wait
}

if ($error->is(ErrorCategory::CONTEXT_LENGTH, ErrorCategory::BAD_REQUEST)) {
    // fixable only by changing the request
}
```

The same applies to the agent loop:

```php
$result = $runner->run($messages, $toolbox, $systemPromptFn, $config);

if (!$result->success && $result->error !== null) {
    echo $result->error->category;
}
```

## What's inside the error

```php
$error->category;        // ErrorCategory::TIMEOUT — what decisions are based on
$error->retryable;       // whether to retry; the default comes from the category
$error->message;         // technical message: for the log, not the UI
$error->httpStatus;      // 429, 500… or null if the failure isn't HTTP
$error->providerCode;    // the provider's machine code, if it sent one
$error->modelKey;        // which catalog model the failure happened on
$error->providerKey;     // through which transport
$error->raw;             // the provider's response body in full
$error->exception;       // ?Throwable, if the failure arrived as an exception

$logger->warning('LLM failed', $error->toArray());   // compact, without the raw body
```

The library doesn't impose user-facing text: that depends on the application. Usually it's your own "category → phrase" map.

## Categories

The category determines both whether we retry the request with the same model and whether we hand the work over to the next model in the chain.

**Retried with the same model, then handed over to the next one:**

- `NETWORK` — dropped connection, DNS: any cURL error other than a timeout.
- `TIMEOUT` — the request timeout expired (cURL 28, HTTP 408).
- `EMPTY_RESPONSE` — a turn with no text and no tool calls.
- `RATE_LIMIT` — HTTP 429.
- `SERVER_ERROR` — HTTP 5xx.
- `INVALID_RESPONSE` — broken JSON, truncated call arguments.
- `UNKNOWN` — none of the above.

**Not retried, but handed over to the next model** (retrying the same one changes nothing, another one may well answer):

- `MODEL_UNAVAILABLE` — no such model, retired, overloaded (404).
- `CONTEXT_LENGTH` — the request doesn't fit the context window.
- `AUTH` — HTTP 401/403; another provider has its own key.

**Neither retried nor handed over** (the request itself is wrong, or the work is cancelled):

- `CONTENT_FILTER` — blocked by moderation.
- `BAD_REQUEST` — HTTP 400/422.
- `DEADLINE` — the run's deadline expired.
- `CONFIG` — catalog error.

The logic is simple: retry whatever might succeed on a second attempt; switch models when the problem is with a specific model or key; stop when the request itself is wrong.

The HTTP status does not fix the category rigidly: for 4xx responses the error text refines it — an overflowing context and a moderation block both arrive as HTTP 400. Responses 5xx and 429 are never overridden by text.

The default behavior is overridden by the policy — `retryOn`, `stopOn`, `then` — see [02-catalog-and-fallback.md](02-catalog-and-fallback.md).

## Order of actions on failure

1. The model the caller picked is retried per its own policy: pause, then retry; a longer pause, then retry again.
2. Retries run out — the **starting** model's policy decides: stop, or hand the work over further. They may also run out before `retries` does, if the model's time budget (`maxWaitSeconds`) is spent.
3. The next model in the chain works under its own policy and its own time budget — the countdown restarts. Models already tried are skipped, and the number of switches is capped by `maxSwitches`.
4. The chain is exhausted, the switches run out, or the call's overall budget (`maxTotalWaitSeconds`) expires — the last error is returned.

Example trace when `gpt-5` is chosen and the chain holds `glm` and `mimo`. The default policy is `retries = 2`, so up to three attempts per model:

```
gpt-5   attempt 1 → timeout
gpt-5   attempt 2 (5s pause)  → empty_response
gpt-5   attempt 3 (10s pause) → timeout
glm     attempt 1 → server_error           ← switch
glm     attempt 2 (5s pause)  → success ✓
```

The response comes from `glm`, and `$response->modelKey` equals `glm` — that's how you see who actually answered.

## Attempt log

Every attempt lands in the response's log:

```php
foreach ($response->attempts as $attempt) {
    printf(
        "%s attempt %d: %s\n",
        $attempt->modelKey,
        $attempt->attempt,
        $attempt->success ? 'success' : $attempt->error->category
    );
}
```

An attempt also carries `latency` (how long it took), `delayBefore` (the pause before it), `willRetry` (whether it will be retried), and `nextDelay` (after how long).

The log is available after the call. If you need to show what's happening right away, subscribe to attempts:

```php
$orchestra = $orchestra->withObserver(function (AttemptLog $attempt): void {
    if (!$attempt->success && $attempt->willRetry) {
        echo "Retrying in {$attempt->nextDelay}s ({$attempt->error->category})\n";
    }
});
```

In the agent loop, the same thing arrives as `Event::ATTEMPT_FAILED` and `Event::MODEL_FALLBACK` events — see [06-events.md](06-events.md).

## Empty and truncated responses

- A turn with no text and no tool calls is an `EMPTY_RESPONSE` failure. It happens on a connection drop and is fixed by a retry.
- A tool call whose arguments don't parse (the response was cut off by the token limit) is `INVALID_RESPONSE`. The tool is not executed on incomplete data.
- A response truncated by the token limit but still meaningful is not treated as an error. Check with `$response->isTruncated()` or `$response->finishReason() === 'length'`.

## Exceptions

There are two of them, and both rarely reach application code.

`LlmException` carries an `ErrorInfo` and lives inside providers: `Orchestra` catches it, records the attempt, and decides what to do next.

`LlmConfigException` is raised while building the catalog and when referring to an unknown model — that is, while the mistake can still be fixed in the config. It's worth catching at application startup to show a clear message.

If you're writing your own provider, the easiest way to get a category is through `ErrorMapper`:

```php
use Hameleon2x\Llm\Error\ErrorMapper;

throw new LlmException(ErrorMapper::fromHttpStatus($status, $body, $decoded));
throw new LlmException(ErrorMapper::fromCurl($errno, $message));
throw LlmException::of(ErrorCategory::EMPTY_RESPONSE, 'The model returned nothing.');
```

## Tool errors

A tool failure is not treated as a call error: return `Tool\Dto\Result::error('...')`, and the model will see `{"error": "..."}` in the tool result, and the loop continues. An exception escaping a tool is caught and closes the call the same way — it never becomes a run failure. More details — [04-tools.md](04-tools.md).

## See also

- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — retry policy and the chain of backup models.
- [06-events.md](06-events.md) — retries and switches in the agent loop.
- [12-custom-provider.md](12-custom-provider.md) — which errors to throw from your own provider.
