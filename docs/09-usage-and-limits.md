**Language:** **English** · [Русский](ru/09-usage-and-limits.md)

# Usage tracking and cost

How token counters are aggregated across an agent run and how to turn them into money.

## The `Usage` DTO

Every `Runner::run()` call returns a [`Result`](../src/Agent/Dto/Result.php) whose `$usage` field is a [`Usage`](../src/Agent/Dto/Usage.php):

| Field              | Type   | Meaning                                                                 |
|--------------------|--------|-------------------------------------------------------------------------|
| `llmCalls`         | `int`  | Number of LLM requests made during the run (including the finish nudge). |
| `promptTokens`     | `int`  | Sum of `prompt_tokens` across all responses.                            |
| `completionTokens` | `int`  | Sum of `completion_tokens` across all responses.                        |
| `totalTokens`      | `int`  | Sum of `total_tokens` across all responses.                             |

`Usage::add(Response $r)` is called by `Runner` for every response, successful or not. Providers do return usage metadata on failed responses (or zeros when they don't), so the counters reflect everything the wire saw.

## What gets counted

- Every regular turn — yes.
- The extra "finish without tools" call triggered when `maxToolCalls` is hit — yes.
- Responses that came back as errors but with `usage` metadata — yes (added as is).
- Local provider fallbacks (when a provider throws and `Client` moves on) — no, those never produce a `Response::success(...)` and `Runner` aborts on the first failed response anyway (see [docs/10-error-handling.md](10-error-handling.md)).

## Reading usage after a run

```php
<?php
use Hameleon2x\Llm\Agent\Runner;
use Psr\Log\LoggerInterface;

/** @var Runner $runner */
/** @var LoggerInterface $logger */
$result = $runner->run($messages, $toolbox, $systemPromptFn, $config);

$logger->info('agent run finished', [
    'success'           => $result->success,
    'turns_used'        => $result->turnsUsed,
    'tool_calls_used'   => $result->toolCallsUsed,
    'llm_calls'         => $result->usage->llmCalls,
    'prompt_tokens'     => $result->usage->promptTokens,
    'completion_tokens' => $result->usage->completionTokens,
    'total_tokens'      => $result->usage->totalTokens,
]);
```

For a single `Client::execute()` call (no agent loop), read the same numbers off the `Response`:

```php
$response->getPromptTokens();
$response->getCompletionTokens();
$response->getTotalTokens();
$response->getLatency();           // seconds, set by BaseProvider
$response->metadata['finishReason'] ?? null;
```

## Cost calculation

The package does not bundle a cost calculator — pricing changes too often and varies by provider. The pattern:

```php
<?php
// Pricing taken from the provider docs (USD per 1M tokens, example values).
$prices = [
    'gpt-4o-mini' => ['prompt' => 0.150, 'completion' => 0.600],
    'gpt-4o'      => ['prompt' => 2.500, 'completion' => 10.000],
];

$p    = $prices[$model] ?? null;
$cost = $p === null
    ? 0.0
    : ($result->usage->promptTokens     / 1_000_000) * $p['prompt']
    + ($result->usage->completionTokens / 1_000_000) * $p['completion'];
```

Wrap it in a `CostCalculator` service on your side if you need it in many places.

## Caveats

- `Usage` does **not** track which model produced each call. If your run can fall back between providers with different prices, log per-response metadata yourself.
- The numbers come straight from the provider. Cache hits, prompt-caching discounts and similar are reflected only if the provider sends them in `usage`.

## See also

- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — where `Usage` is populated.
- [docs/08-config-reference.md](08-config-reference.md) — limits that bound a run.
- [docs/10-error-handling.md](10-error-handling.md) — failure modes and how usage behaves on errors.
