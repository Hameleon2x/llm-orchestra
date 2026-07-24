**Language:** **English** · [Русский](ru/09-usage-and-limits.md)

# Usage and limits

How many tokens and how much money a call cost, and what bounds limit a run.

## Tokens of a single response

```php
$response = $orchestra->execute(Request::simple('Answer briefly.', 'What is PHP?'));

echo $response->usage->promptTokens;      // request tokens
echo $response->usage->completionTokens;  // response tokens
echo $response->usage->totalTokens;       // the sum
```

Besides the three main counters, `Dto\Usage` holds what providers increasingly report:

- **`cachedTokens`** — the part of the prompt served from the provider's cache. Such tokens are usually cheaper than regular ones.
- **`reasoningTokens`** — reasoning tokens for reasoning models. They are already included in `completionTokens`; this is a clarification, not an addition.
- **`cost`** — the actual cost in dollars, if the provider sent it. Gateways like OpenRouter and Requesty do this; direct APIs usually don't.
- **`calls`** — how many model calls are accounted for. For a single response this is `1`; for a run, however many calls were made.

## Tokens of a run

`Agent\Dto\Result::$usage` — the same object, but summed across all model calls of the run:

```php
$result = $runner->run($messages, $toolbox, $systemPromptFn, $config);

echo $result->usage->calls;         // how many times the model was called
echo $result->usage->totalTokens;   // how many tokens were spent in total
echo $result->usage->cost;          // null if the provider doesn't report cost
```

The sum includes all loop turns and the extra request made when the tool-call limit runs out. It does not include: failed attempts (on failure the provider doesn't send a `usage` block) and sub-runs that you start from your own tools — they have their own `Result` and their own `Usage`.

### Per-model breakdown

If a switch to a fallback model happened on failure, two models with different pricing worked within a single run. The total token count then says nothing about the cost, so there is a breakdown:

```php
foreach ($result->usage->byModel as $modelKey => $usage) {
    printf("%s: %d tokens\n", $modelKey, $usage->totalTokens);
}
// glm-4.6:   1200 tokens
// mimo-2.5:  3400 tokens
```

For logs, `toArray()` is handy — it returns only the populated fields:

```php
$logger->info('LLM run', $result->usage->toArray());
```

## Cost

The most accurate value is the provider's `Usage::$cost`. When it's absent, cost can be estimated from catalog pricing. Pricing is optional and given per million tokens:

```php
'models' => [
    'gpt-5' => [
        'provider' => 'openai',
        'name'     => 'gpt-5',
        'pricing'  => ['in' => 1.25, 'out' => 10.0],
    ],
],
```

```php
$estimate = $registry->costOf('gpt-5', $usage->promptTokens, $usage->completionTokens);
// null if the model has no pricing set
```

## Run limits

Five limiters, each with its own purpose:

- **`Config::$maxTurns`** — how many times the model can be called. On exhaustion the run returns `Finish::TURNS_EXHAUSTED` and the text `turnsExhaustedText` in `$content`.
- **`Config::$maxToolCalls`** — how many tools can be executed. On exhaustion, one more request without tools is made so the model can sum up the collected data; the result is marked `Finish::TOOL_LIMIT`.
- **`Config::$deadlineSeconds`** — the maximum run duration. Checked before every turn; on expiry an error of category `deadline` is returned along with the full history.
- **`ErrorPolicy::$maxWaitSeconds`** — how long a single model may take: its requests and the pauses between retries. On exhaustion retries stop and the work goes to the next model in the chain, whose own countdown starts from scratch.
- **`maxTotalWaitSeconds`** (a catalog key) — how long the whole call may take, including every switch. On exhaustion both retries and switches stop, and the last error is returned.

The first three are set in the run config ([08-config-reference.md](08-config-reference.md)), the last two in the catalog and its error policy ([02-catalog-and-fallback.md](02-catalog-and-fallback.md)).

A useful rule: keep `maxTurns` higher than `maxToolCalls` — the defaults (`40` and `30`) follow it. Every turn with tool calls spends at least one unit of `maxToolCalls`, and a turn without calls ends the loop, so with that ratio the call limit triggers first: the run ends with a final answer from the model rather than a service placeholder about exhausted turns.

## Counters after a run

```php
$result->turnsUsed;        // how many turns were made
$result->toolCallsUsed;    // how many tools were executed
$result->finish;           // why the loop stopped
count($result->attempts);  // how many model call attempts were made, including retries
```

## See also

- [08-config-reference.md](08-config-reference.md) — where run limits are set.
- [10-error-handling.md](10-error-handling.md) — the attempt log and error categories.
