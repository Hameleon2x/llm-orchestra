[![en](https://img.shields.io/badge/lang-en-red.svg)](UPGRADING.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](UPGRADING.ru.md)

# Upgrading

Breaking changes between major versions. Per-release notes: [CHANGELOG.md](CHANGELOG.md).

## 0.3.x → 0.4.x

The unit of choice is the model, not the provider. `Client` with its provider list is replaced by a catalog (`Registry`) and an executor (`Orchestra`). There is no compatibility layer: configuration and error handling must be rewritten.

### 1. Configuration: providers and models are separate

Before:

```php
$client = new Client($logger);
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => '...', 'model' => 'anthropic/claude-3.5-sonnet', 'priority' => 1],
    ['class' => OpenAiProvider::class,     'token' => '...', 'model' => 'gpt-4o-mini',                'priority' => 2],
];
```

After:

```php
$registry = Registry::fromArray([
    'providers' => [
        'openrouter' => ['class' => OpenRouterProvider::class, 'token' => '...'],
        'openai'     => ['class' => OpenAiProvider::class,     'token' => '...'],
    ],
    'models' => [
        'sonnet' => ['provider' => 'openrouter', 'name' => 'anthropic/claude-3.5-sonnet', 'fullName' => 'Claude Sonnet'],
        'mini'   => ['provider' => 'openai',     'name' => 'gpt-4o-mini',                 'fullName' => 'GPT-4o mini'],
    ],
    'defaultModel' => 'sonnet',
    'fallback' => ['sonnet', 'mini'],
]);

$orchestra = new Orchestra($registry, $logger);
```

- `priority` is gone: order comes from `fallback`.
- `supportedModels` is gone: a model belongs to its provider.
- If you store the model key in your database, list the old slugs under `aliases` — stored values resolve to the new key with no data migration.

### 2. Calls: the model is a key

```php
// before
$response = $client->execute(Request::simple($system, $user));
// after
$response = $orchestra->execute(Request::simple($system, $user), 'sonnet');   // omit the key to use defaultModel
```

Per-call generation params go through `Request::setTemperature()`/`setMaxTokens()` or `setParams(GenerationParams)`; `Request::$model` is removed.

### 3. Errors: a category instead of a string

```php
// before
if (!$response->isSuccess()) {
    if ($response->status === Status::RATE_LIMIT) { ... }
    log($response->error);                       // string
}

// after
if (!$response->isSuccess()) {
    if ($response->error->is(ErrorCategory::RATE_LIMIT)) { ... }
    if ($response->error->isConnectionDrop())            { ... }   // network, timeout, empty turn
    log($response->error->toArray());
}
```

- `Enum\Status` is removed.
- `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException` are removed. A custom provider throws `LlmException` with an `ErrorInfo`; the easiest way to build one is `ErrorMapper::fromHttpStatus()` / `fromCurl()` / `fromThrowable()`.
- Drop any substring matching on error text — the category covers it.

### 4. Agent loop

```php
$config = new Config();
$config->model = 'sonnet';                    // new: the run's model
$config->params->temperature = 0.2;           // was: $config->temperature
$config->params->maxTokens   = 8000;          // was: $config->maxTokens
$config->extraParams = ['plugins' => [...]];  // was: $config->plugins

$result = (new Runner($orchestra))->run($messages, $toolbox, $systemPromptFn, $config, $emit);

if (!$result->success) {
    echo $result->error->category;            // was: the string $result->error
}
```

- `Runner`'s constructor takes an `Orchestra` instead of a `Client`.
- An empty turn is no longer a success carrying "Нет ответа от модели." — it is an `empty_response` error. Remove checks against that text.
- The stop reason is `Result::$finish` (`Finish::COMPLETED`, `TOOL_LIMIT`, `TURNS_EXHAUSTED`, `DEADLINE`, `ERROR`, `SUSPENDED`).
- Remove hand-written "retry the whole run on a dropped connection" loops: retries and model switches are `Orchestra`'s job, and they reach the UI as `Event::ATTEMPT_FAILED` and `Event::MODEL_FALLBACK`.
- Tool-argument checking is on by default (`Config::$toolArgsGuard`); remove your own copy of it.

### 5. Smaller things

- `Agent\Dto\Usage` → `Dto\Usage`.
- `Response::$model` (the slug) is now `$modelName`; `$modelKey` holds the catalog key.
- A custom HTTP client is injected via the provider config (`'httpClient' => $client`), and its method takes headers and a timeout: `chat(array $payload, array $headers = [], ?int $timeout = null)`.
- The `CurlChatClient::DEBUG` constant is replaced by `'debug' => true` in the provider config (logs through PSR-3).

## 0.2.x → 0.3.x

Tool notes are no longer appended to the system prompt — the `Runner` now injects them into the tool's RESULT on first use (a stable system prefix keeps the provider's prompt cache alive). Methods renamed, `SystemPromptComposer` removed.

Project-wide rename in your code:

```
ToolInterface::appendToSystemPromptAfterUse()  →  ToolInterface::firstUseHint()
ToolboxInterface::systemPromptAddition($name)  →  ToolboxInterface::firstUseHint($name)
```

- Update every class implementing `ToolInterface` (or extending `AbstractTool`) — otherwise PHP throws `Fatal error: ... contains N abstract methods`. A tool with no note can drop the method entirely: `AbstractTool::firstUseHint()` now returns `''` by default.
- A hand-rolled `ToolboxInterface` (not via `AbstractToolbox`): rename `systemPromptAddition()` to `firstUseHint()` and add `firstUseHintKey(string $name): string` (return `AbstractTool::DEFAULT_FIRST_USE_HINT_KEY` if the key doesn't matter).
- `Agent\SystemPromptComposer` is gone. If you used it (e.g. to render the "full" system prompt in a UI), render the base prompt from `$systemPromptFn` instead; tool notes now live inside tool results under `firstUseHintKey()` (default `hint_use`).
- Optional: if the default key `hint_use` collides with a field in a tool's result, override `firstUseHintKey()` in that tool.

## 0.1.x → 0.2.x

`ToolInterface::getSystemPromptDescription()` renamed to `ToolInterface::appendToSystemPromptAfterUse()`. Signature and semantics unchanged.

Project-wide rename in your code:

```
getSystemPromptDescription  →  appendToSystemPromptAfterUse
```

Every class implementing `ToolInterface` (or extending `AbstractTool`) must be updated — otherwise PHP throws `Fatal error: ... contains 1 abstract method`.
