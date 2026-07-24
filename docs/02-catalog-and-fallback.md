**Language:** **English** · [Русский](ru/02-catalog-and-fallback.md)

# Model catalog and fallback models

The catalog describes what you work with. It has two main parts: **providers** (where to call and how to authenticate) and **models** (exactly what to call and with what settings).

The key idea: the unit of choice is the model, not the provider. A provider knows only an address and a token; a model knows its own slug, its own parameters, and its own policy on failure. So the same model behind two providers is two catalog entries with different keys, and nothing gets confused.

## Growing from a minimal config

Start with what you cannot do without:

```php
$registry = Registry::fromArray([
    'providers' => [
        'openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...'],
    ],
    'models' => [
        'mini' => ['provider' => 'openai', 'name' => 'gpt-4o-mini'],
    ],
    'defaultModel' => 'mini',
]);
```

`mini` is the **catalog key**: it goes into your UI and your database. `gpt-4o-mini` is the **slug** the provider's API knows the model by. This separation exists because slugs differ across gateways, while the key stays yours.

Add a second model, display labels, and generation parameters:

```php
'models' => [
    'mini' => [
        'provider'    => 'openai',
        'name'        => 'gpt-4o-mini',
        'fullName'    => 'GPT-4o mini',
        'description' => 'fast and cheap, for simple tasks',
        'params'      => ['temperature' => 0.2, 'maxTokens' => 2000],
    ],
    'sonnet' => [
        'provider'    => 'openrouter',
        'name'        => 'anthropic/claude-3.5-sonnet',
        'fullName'    => 'Claude 3.5 Sonnet',
        'description' => 'for complex tasks and long history',
    ],
],
```

Now `$registry->labels()` returns `['mini' => 'GPT-4o mini', 'sonnet' => 'Claude 3.5 Sonnet']` — a ready-made list for a dropdown menu.

And finally, tell it what to do on failure:

```php
'fallback'      => ['mini', 'sonnet'],     // the order in which other models are tried
'maxSwitches'   => 2,                      // no more than two switches per call
'defaultPolicy' => ['retries' => 2, 'delay' => 5, 'backoff' => 2],
```

That is the whole catalog. Next — what else you can specify in it.

## Provider

```php
'providers' => [
    'openrouter' => [
        'class'   => OpenRouterProvider::class,
        'token'   => 'sk-or-...',
        'timeout' => 180,
        'headers' => ['HTTP-Referer' => 'https://example.com', 'X-Title' => 'My App'],
    ],
],
```

What you can set:

- **`class`** (required) — a `ProviderInterface` implementation. Built in: `OpenAiProvider`, `OpenRouterProvider`, `RequestyProvider` — they speak the same OpenAI-compatible API and differ only in the address. Your own provider — [12-custom-provider.md](12-custom-provider.md).
- **`token`** — the auth key. Empty is fine: local gateways don't require it.
- **`baseUrl`** — the API address without the endpoint path. Defaults to the value from the provider class; set it if you work through a proxy or a self-hosted gateway.
- **`timeout`** — request timeout in seconds, `120` by default. A model can override it with its own.
- **`headers`** — headers for all requests of this provider.
- **`extraParams`** — extra payload fields for all its requests.
- **`capture`** — the response field extraction map, covered below.
- **`debug`** — when `true`, the payload and the raw response are logged at `debug` level.
- **`keepRaw`** — when `false`, the raw response is not stored in `Response`. Stored by default.
- **`httpClient`** — a custom HTTP client, an object or a factory, see [11-custom-http-client.md](11-custom-http-client.md).
- **`meta`** — any application data; the library never touches it.

## Model

```php
'glm' => [
    'provider'    => 'requesty',
    'name'        => 'zai/GLM-4.6',
    'fullName'    => 'GLM-4.6',
    'description' => 'fast, careful with tools',
    'params'      => ['temperature' => 0.2],
],
```

What you can set:

- **`provider`** (required) — a provider key from the same catalog.
- **`name`** (required) — the model slug for the API.
- **`fullName`** — the display name for the UI. Defaults to the key.
- **`description`** — what the model is good at; handy to show in a picker.
- **`params`** — `temperature`, `topP`, `maxTokens`, `seed`.
- **`unsupported`** — parameters the model doesn't accept. Always stripped from the request, no matter who set them.
- **`extraParams`** — payload fields specific to this model.
- **`headers`** — headers specific to this model.
- **`capture`** — a response field extraction map on top of the provider's map.
- **`policy`** — its own retry policy, see below.
- **`timeout`** — its own timeout in seconds; useful for slow reasoning models.
- **`pricing`** — `['in' => 1.25, 'out' => 10.0]`, price per million tokens, for cost estimates.
- **`tags`** — labels for grouping in your application, e.g. `['fast', 'tools']`.
- **`meta`** — arbitrary application data; the library doesn't touch it.

### One slug, two modes

Two entries with the same `name` but different `extraParams` are two items in the picker. The key is stored in your database, so later you can see which mode the model answered in.

```php
'glm'       => ['provider' => 'requesty', 'name' => 'zai/GLM-4.6', 'fullName' => 'GLM-4.6'],
'glm-think' => [
    'provider'    => 'requesty',
    'name'        => 'zai/GLM-4.6',
    'fullName'    => 'GLM-4.6 (deep thinking)',
    'extraParams' => ['thinking' => ['type' => 'enabled']],
    'params'      => ['maxTokens' => 16000],
],
```

### A model that doesn't accept sampling

Reasoning models usually reject `temperature` and `top_p`, but accept their own fields and take longer to answer:

```php
'gpt-5' => [
    'provider'    => 'openai',
    'name'        => 'gpt-5',
    'unsupported' => ['temperature', 'topP'],
    'extraParams' => ['reasoning_effort' => 'high'],
    'timeout'     => 600,
],
```

List in `unsupported` exactly what the model rejects. A neighbouring case is when parameters are supported but not together: some providers answer "temperature and top_p cannot both be specified". No special mechanism is needed for that — unset parameters never reach the request, and `topP` is unset by default, so the conflict only appears if you set both yourself. Then it's enough to drop the redundant one, from `params` or through that model's `unsupported`.

## Where the final request comes from

Settings are set at three levels, and this is the main thing to understand about the catalog.

Generation parameters (`temperature`, `topP`, `maxTokens`, `seed`) merge **by explicitness**: catalog (`defaultParams`) → model (`params`) → the specific call. The closer to the call, the stronger it wins. `unsupported` is applied on top of the result: it isn't a preference but a constraint of the model, so it overrides everything.

Arbitrary payload fields and headers merge in the same order: provider → model → call. Merge rules:

- associative arrays merge deeply — `reasoning.effort` doesn't wipe out `reasoning.enabled`;
- lists are replaced wholesale — `provider.order` is a choice, not an accumulation;
- a `null` value removes the key: this is how a model cancels what its provider set.

```php
// provider: 'extraParams' => ['provider' => ['order' => ['DeepInfra']]]
// model:    'extraParams' => ['thinking' => ['type' => 'enabled']]
// call:     $config->extraParams = ['session_id' => 'run_17'];
// all three fields end up in the request
```

## Retry policy

There is exactly one retry level in the library — this one. The transport doesn't run any loops of its own, so the wait time on failure is predictable.

```php
'defaultPolicy' => [
    'retries'     => 2,
    'delay'       => 5,
    'backoff'     => 2,
    'then'        => 'fallback',
    'perCategory' => [ErrorCategory::RATE_LIMIT => ['retries' => 3, 'delay' => 15]],
],
```

- **`retries`** — how many extra attempts to make with the same model. `2` by default, meaning three calls in total.
- **`delay`** — the base pause before a retry, in seconds, `5` by default.
- **`backoff`** — the multiplier the pause grows by with each attempt, `2` by default: that gives 5s, then 10s.
- **`maxDelay`** — the cap for a single pause, `60` seconds by default.
- **`then`** — what to do when retries run out: `'fallback'` (hand the work to the next model) or `'stop'` (return the error). `'fallback'` by default.
- **`perCategory`** — overrides for individual error categories. A typical case is rate limiting: wait longer and more persistently.
- **`retryOn`** — an explicit list of categories to retry. If empty, the category itself decides.
- **`stopOn`** — categories for which we don't switch to a backup model, even if `then` allows it.
- **`maxWaitSeconds`** — the cap on time spent on this model: its requests plus the pauses between retries. Unlimited by default.

### A policy of a model's own, and of a provider

The same section can be attached to a provider and to an individual model — the key is called `policy` in both places:

```php
'providers' => [
    'slow-gateway' => [
        'class'  => OpenRouterProvider::class,
        'token'  => 'sk-or-...',
        'policy' => ['retries' => 1, 'delay' => 10],     // for every model of this gateway
    ],
],

'models' => [
    'gpt-5' => [
        'provider' => 'openai',
        'name'     => 'gpt-5',
        'policy'   => ['retries' => 1, 'then' => 'stop'],  // so an expensive model isn't silently swapped out
    ],
],
```

**A policy is not assembled from pieces: the closest one applies, and it applies in full.** The lookup order is: the run's policy (`Config::$policy` or `Orchestra::withPolicy()`) → the model's `policy` → its provider's `policy` → the catalog's `defaultPolicy`. The first one found is used; the rest are not mixed in.

Two consequences follow.

Fields left unset in the policy that was found take the defaults of the `ErrorPolicy` class (`retries = 2`, `delay = 5`, `backoff = 2`, `maxDelay = 60`, `then = fallback`), not the catalog's values. In the example above `gpt-5` will pause for 5 seconds even if `defaultPolicy` says otherwise.

Per-category refinements belong to their policy in full as well. If a model defines `policy`, the catalog's `perCategory` does not apply to it — list the rules you need in the model's own section:

```php
'policy' => [
    'retries'     => 3,
    'perCategory' => [ErrorCategory::TIMEOUT => ['retries' => 0]],
],
```

This trade-off is deliberate: settings get repeated, but the config shows at a glance how a given model behaves, with nothing to keep in your head about what overrides what.

Note who is asked for what: `retries`, `delay` and `backoff` come from the model being executed right now, while the decision to switch or stop (`then`, `stopOn`) comes from the run's starting model. So a backup model's `policy` governs its own retries but not whether the chain moves on.

Which error categories retry by default and which don't — see [10-error-handling.md](10-error-handling.md).

### Different behaviour for different errors

`perCategory` sets its own `retries`, `delay`, `backoff` and `maxDelay` for specific categories. A typical set:

```php
'defaultPolicy' => [
    'retries'     => 2,
    'delay'       => 5,
    'backoff'     => 2,
    'then'        => 'fallback',
    'perCategory' => [
        // rate limiting: wait longer but persist — it clears on its own
        ErrorCategory::RATE_LIMIT   => ['retries' => 3, 'delay' => 15],
        // timeout on a slow model: retrying is nearly pointless, switching is faster
        ErrorCategory::TIMEOUT      => ['retries' => 0],
        // the provider's server hiccuped: one quick retry without a long wait
        ErrorCategory::SERVER_ERROR => ['retries' => 1, 'delay' => 1],
    ],
],
```

Two neighbouring levers work on whole categories:

- **`retryOn`** — a whitelist: only the listed categories are retried, the rest go straight to the fallback chain. When the list is empty, the category itself decides.
- **`stopOn`** — categories that must not switch to another model even when `then` allows it. For example, `stopOn => [ErrorCategory::CONTENT_FILTER]` if a moderation block should reach the user instead of being masked by another model's answer.

There is no per-category chain: the chain is single, and what differs is only how many times and with what pauses you try before moving on to it.

### How long to wait for an answer

The timeout of a single request is set on the provider and overridden by the model when needed:

```php
'providers' => [
    'openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'timeout' => 120],
],
'models' => [
    'gpt-5' => ['provider' => 'openai', 'name' => 'gpt-5', 'timeout' => 600],   // reasons for a long time
],
```

The value goes to the transport as the timeout of the whole request; the connection timeout is taken as `min(30, timeout)`.

It is easy to end up with unpleasant arithmetic here: a 600-second timeout with `retries = 2` means three attempts of almost ten minutes each, and only then a switch to the next model. So besides the timeout of a single request there are two caps on time: per model and for the whole call.

```php
'defaultPolicy' => [
    'maxWaitSeconds' => 300,      // per model: its requests and the pauses between retries
],
'maxTotalWaitSeconds' => 900,     // the whole call, including every switch
```

**`maxWaitSeconds` is about a model**, which is why it lives in the policy: next to `retries`, `delay` and `backoff`, so it can be set on the model, on its provider or in `defaultPolicy`. It counts from that model's first attempt and **restarts after a switch**: if a slow starting model burned its five minutes, the backup gets its own five, not the remainder. When the budget runs out, retries stop and the work goes to the next model in the chain.

**`maxTotalWaitSeconds` is about the whole call**, so it is a catalog key next to `fallback` and `maxSwitches`: it does not depend on which model is running. It counts from the start of the call; when it runs out, both retries and switches stop and the last error is returned.

None of the caps is set by default: with the default 120 s timeout, 2 retries and 2 switches a single call takes about twenty minutes in the worst case, and an agent run takes hours. If you call this from a web request, set the caps explicitly.

Both caps account for all the time spent, not just the pauses, and neither interrupts a request already in flight — that is the `timeout`'s job. So the actual duration may exceed a cap by the length of the last request.

Here is how it looks with `timeout = 180` and `retries = 2`:

```
glm     attempt 1 → timeout (180 s)
        pause 5 s
glm     attempt 2 → timeout (180 s)      ← the model took 365 s, the 300 s budget is out
                                           the retry is cancelled even though retries allowed it
mimo    attempt 1 → timeout (180 s)      ← switch: mimo gets its own 300 s
        pause 5 s
mimo    attempt 2 → success ✓            ← ~730 s in total, the 900 s overall cap is untouched
```

## The fallback chain

There is one flat chain per catalog: models don't have their own continuation lists. This removes the question of whose list wins, and looping is impossible — models already tried are skipped.

```php
'fallback'    => ['glm', 'mimo', 'gpt-5-mini'],
'maxSwitches' => 2,
```

Here's what it looks like if you called `gpt-5` (it isn't in the chain itself — that's fine):

```
gpt-5   attempt 1 → timeout
gpt-5   attempt 2 (5s pause)  → empty_response
gpt-5   attempt 3 (10s pause) → timeout        ← three attempts: that is retries = 2
glm     attempt 1 → server_error               ← switch 1
glm     attempt 2 (5s pause)  → success ✓      ← the run continues on glm
```

The decision to switch or stop is made by the policy of the **starting** model — the one the caller picked. Intermediate links of the chain aren't asked for `then`: otherwise the question of whose decision wins would come back.

`fallback` and `maxSwitches` answer different questions: the first sets the order, the second sets how many steps along that order are allowed per call (`2` by default). The cap exists so a failing request doesn't walk a long chain with several attempts per model. Because of it, part of the chain may stay unused: with `maxSwitches = 2` the third model is reached only when the starting model is itself in the chain — otherwise both steps are spent on the first two entries. If you want the chain always traversed in full, set `maxSwitches` to its length.

You can override the chain for a specific call: `$orchestra->withFallback(['glm'], 1)`.

## The capture map: data outside the contract

Providers keep putting more and more next to the answer: reasoning traces of reasoning models, web-search citations, moderation refusals. The library throws nothing away — the raw response is available via `$response->raw()`. But parsing someone else's structure in application code is inconvenient, so there is a map: it extracts the fields you need and puts them under your own names.

```php
'capture' => [
    'reasoning'      => ['choices.0.message.reasoning_content', 'choices.0.message.reasoning'],
    'thinkingTokens' => 'usage.thinking_tokens',
],
```

```php
$response->extra('reasoning');   // whatever was found at the first non-empty path
```

A value is a path or a list of paths; the first non-empty one wins. A list is needed when different gateways name the same field differently: the application reads `extra('reasoning')` and notices nothing when switching between providers.

For OpenAI-compatible providers, the built-in map already covers `reasoning`, `annotations`, `refusal`, `citations`, `systemFingerprint`, and the upstream name. Config extends and overrides it.

## Config validation

The catalog is validated as a whole at build time: at least one provider and one model must be defined; the provider a model references must exist; so must the keys in the chain and the default model; the provider class must exist and implement `ProviderInterface`. Any violation raises `LlmConfigException` with a clear message, before the first request.

## What the catalog can return

```php
$registry->has('glm');                     // whether such a model exists
$registry->normalize($fromForm, 'glm');    // coerce a value to a key, else use the fallback
$registry->model('glm');                   // ModelDefinition; throws LlmConfigException if absent
$registry->findModel('glm');               // same, but null instead of an exception
$registry->labels();                       // ['glm' => 'GLM-4.6', ...] — for a picker
$registry->all();                          // all models
$registry->byTag('fast');                  // models with a label
$registry->byProvider('requesty');         // models of one transport
$registry->provider('requesty')->token;    // transport settings
$registry->costOf('glm', 1000, 500);       // cost estimate from catalog pricing
```

`normalize()` is especially handy where a value comes from outside — a form or a database: it replaces an unknown or empty value with the fallback, and coerces a known one to the canonical key.

## See also

- [01-getting-started.md](01-getting-started.md) — the first request.
- [10-error-handling.md](10-error-handling.md) — error categories and the attempt log.
- [03-logging.md](03-logging.md) — what is logged on retries and switches.
