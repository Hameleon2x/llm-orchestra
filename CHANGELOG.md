[![en](https://img.shields.io/badge/lang-en-red.svg)](CHANGELOG.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](CHANGELOG.ru.md)

# Changelog

All notable changes to `hameleon2x/llm-orchestra` are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.4.0] - 2026-07-24

The unit of choice is now the model, not the provider. Model catalog, one flat fallback chain, typed errors instead of strings, three layers of data in the response. Breaking release with no compatibility layer: `Client` is replaced by `Registry` + `Orchestra`. Migration: [UPGRADING.md](UPGRADING.md).

### Added

- **`Registry` — a catalog of providers and models.** A provider describes transport only (class, token, `baseUrl`, timeout, headers); a model is a catalog key, an API slug (`name`), labels (`fullName`, `description`), generation params and an error policy. The same model behind two providers is two entries with different keys, so slugs never collide. The catalog is built from an array (`Registry::fromArray()`) or programmatically (`addProvider()`/`addModel()`) and is **validated as a whole at build time**: a missing provider or a typo in the fallback chain raises `LlmConfigException` right away instead of at failure time.
- **`Orchestra` — the executor.** Takes a `Request` and a model key, applies the error policy, keeps an attempt log. It never throws: the result is a `Response` with either `content` or `error`. Copies with overrides: `withPolicy()`, `withFallback()`, `withTotalWaitSeconds()`, `withObserver()`.
- **One flat fallback chain per catalog.** `fallback` is an ordered list of keys, `maxSwitches` caps the switches per call. The failed model and everything already tried are skipped, so the question "whose continuation list wins" never arises. The policy's `then` (`fallback`/`stop`) is read from the run's starting model.
- **Typed errors.** `Error\ErrorCategory` (`network`, `timeout`, `empty_response`, `rate_limit`, `server_error`, `invalid_response`, `model_unavailable`, `context_length`, `content_filter`, `auth`, `bad_request`, `deadline`, `config`, `unknown`), `Error\ErrorInfo` (category, HTTP status, provider code, model and provider keys, raw body, `is()`, `isConnectionDrop()`), and `Error\ErrorMapper` — the single place where cURL codes, HTTP statuses and error texts are interpreted; custom providers use it too.
- **Three layers of response data.** `Response::$metadata` is ours; `Response::extra()` holds provider data normalized to your names via the `capture` map; `Response::raw($path)` gives the whole payload with dot-path access such as `choices.0.message.reasoning_content`. A new provider field no longer requires a library release — one line in `capture` is enough. The built-in map covers `reasoning` (both spellings), `annotations`, `refusal`, `citations`, `systemFingerprint` and the upstream name.
- **Three levels of arbitrary payload fields and headers:** provider → model → call. Associative arrays merge recursively, lists are replaced wholesale, `null` removes a key. That is how you enable extended thinking on one model, `reasoning_effort` on another and `HTTP-Referer` for a whole provider. `unsupported` strips a parameter the model rejects (`temperature` on reasoning models) no matter who set it.
- **`Usage` extended:** `cachedTokens`, `reasoningTokens`, the provider's actual `cost` and a `byModel` breakdown — with fallback a single run may involve models with different pricing. Optional catalog `pricing` gives an estimate via `Registry::costOf()` when the provider reports no cost.
- **Application callbacks are isolated.** An exception from the event sink (`$emit`) or the attempt observer (`withObserver()`) no longer aborts the run: it is logged (`LLM event sink failed`, `LLM attempt observer failed`). An exception from a tool closes that call with an error — the model sees it and moves on, while the details go to the log (`LLM tool threw an exception`). The exception message is not shown to the model by default: `Agent\Dto\RunOptions::$exposeToolExceptions` opts in, trimmed to 300 characters.
- **`Agent\Runner` takes a PSR-3 logger** as the second constructor argument.
- **The API format belongs to the provider, not the transport.** The endpoint path comes from `BaseProvider::endpointPath()`, the `stream` field is set by `OpenAiProvider`, and `CurlChatClient` receives a ready URL as its first constructor argument. A provider with a different path no longer needs its own transport. A custom client factory also receives the ready URL as its second argument.
- **The run deadline holds inside a turn.** `RunOptions::$deadlineSeconds` is projected onto every executor call through `Orchestra::withTotalWaitSeconds()`, so retries and switches cannot carry the run past it.
- **The time caps are now hard:** the request timeout is clamped by what is left of the call budget, so a request never outlives `maxTotalWaitSeconds` or the run deadline.
- **Transport configuration errors** (a malformed URL, an unknown protocol, a certificate problem — cURL 1/3/60/77, plus a redirect in reply to a POST) now map to category `config`: retrying them or switching models changes nothing.
- **A PHP-level error** (`TypeError` and other `\Error`s) is no longer treated as a temporary failure: category `config`, no retries and no model switching.
- **A typo in a config value no longer passes silently:** `then`, the categories in `retryOn`, `stopOn` and `perCategory`, and the names in `unsupported` are checked against the allowed sets while the catalog is built. Otherwise `'Stop'` instead of `'stop'` would quietly permit the very model switching it is meant to forbid, and a non-empty `retryOn` without a single real category would disable retries entirely.
- **A failure in application code no longer costs you the history:** an exception from the system prompt or the tool registry comes back as a `Result` with category `config` instead of escaping; a failing `firstUseHint()` is only logged.
- **`Tool\ToolArgsGuard`** — detects leaked tool-call markup in arguments (`<parameter name=…>`, `<invoke …>`, tags named after parameters). Enabled by default (`Agent\Dto\RunOptions::$toolArgsGuard`), disabled by assigning `null`, extendable with your own patterns. A tool with corrupted arguments is not executed — the model gets an error and re-sends the call.
- **`Agent\Enum\Finish`** — why the run stopped (`completed`, `tool_limit`, `turns_exhausted`, `deadline`, `error`, `suspended`) in `Agent\Dto\Result::$finish`. Previously outcomes differed only by placeholder text.
- **`Agent\Dto\RunOptions::$deadlineSeconds`** — a wall-clock limit for the run; on expiry it returns a `deadline` error with the full history intact.
- **The catalog's `defaultRun` section and `Registry::runOptions()`** — defaults for run options (turn and tool-call limits, deadline, generation params, texts) are set in the config next to the models, and `runOptions()` returns a ready object to adjust for the specific run. No more repeating those numbers as constants in every calling service.
- **The texts sent to the model are configurable:** `toolLimitReachedText`, `toolFailedText`, `toolFailedPrefix`, `encodeFailedText` and `firstUseResultKey` in `Agent\Dto\RunOptions` — these strings used to be hard-coded in `Runner`.
- **`Event::ATTEMPT_FAILED` and `Event::MODEL_FALLBACK`** — a failed attempt (with "will retry", the delay, and the `attempt`/`max_attempts` counters for a "retry N of M" indicator) and a model switch. The UI can show retries as they happen instead of reconstructing them afterwards.
- **`Http\ChatClientInterface` accepts headers and a per-call timeout**, and a custom client is injected through the provider config (`httpClient` — an object or a factory) instead of subclassing the provider.
- **The escalation chain lives only in the catalog.** `fallback`, `maxSwitches` and the error policy describe the installation, not the task, so there are no run options for them: a run with a special chain gets its own `Orchestra::withFallback()`/`withPolicy()`.
- **`Support\SleeperInterface`** — the pause between attempts, replaceable in tests and in web contexts.
- **Debugging over PSR-3:** `'debug' => true` in the provider config logs the outgoing payload and the raw response at `debug` level (this used to be a constant inside `CurlChatClient`).

### Changed

- **`Agent\Dto\Config` is renamed to `Agent\Dto\RunOptions`.** The old name was misleading: this is not application configuration but an argument of `Runner::run()` — the object lives for exactly one run.

- **A call now has a default time cap:** the catalog `maxTotalWaitSeconds` is 600 seconds instead of "unlimited"; an explicit `null` removes it. Without a cap the default 120 s timeout, 2 retries and 2 switches stretched a single call to almost twenty minutes.

- **One retry level instead of two.** The transport no longer runs its own loop: retries are governed by the model policy (`retries`, `delay`, `backoff`, `maxDelay`, `perCategory`, `retryOn`, `stopOn`, `maxWaitSeconds`). Waiting time on failure is now predictable; the request timeout is the smaller of the configured one and what is left of the call budget.
- **The error policy is set at three levels and never mixed between them.** A `policy` section exists on a model and on a provider, and the catalog defines `defaultPolicy`. The closest one applies — the model's, then its provider's, then the catalog's — and it applies in full: unset fields take `ErrorPolicy` defaults rather than values from a neighbouring level. So the config shows what governs a given call without working out what overrides what.
- **An empty turn is an `empty_response` error, not a "success" with a placeholder.** The `Runner` used to return the text "Нет ответа от модели." and callers had to compare strings.
- **Truncated tool-call arguments** (cut off by the token limit) are reported as `invalid_response` — the tool is not executed on partial data.
- `Agent\Runner` runs on top of `Orchestra`; the run's model is a catalog key (`RunOptions::$model`), and after a switch the run continues on the model that answered (`RunOptions::$stickyFallback`).
- `Agent\Dto\Result` carries an `ErrorInfo` instead of an error string, plus the model key, the attempt log and the last `Response`.
- Generation parameters live in `Config\GenerationParams` (`temperature`, `topP`, `maxTokens`, `seed`) and merge by explicitness: catalog → model → call.
- `Usage` moved from `Agent\Dto` to `Dto` — it is useful without the agent loop.
- `Event::ASSISTANT_MESSAGE` meta now carries `extra` (including the model's reasoning), the turn's `usage` and the model key.
- **Two time caps instead of one.** `maxWaitSeconds` in the policy caps **a single model** (its requests and the pauses between retries) and restarts after a switch, while the new catalog key `maxTotalWaitSeconds` caps **the whole call**, switches included. Previously the single cap lived in the model's policy yet measured the entire run, so a slow starting model ate the backups' budget.
- **`RunOptions::$maxTurns` now defaults to `40`** (with `maxToolCalls = 30`). Turns must cover every allowed tool call, otherwise the run hits the turn limit and returns a service placeholder instead of the model's final answer.

### Removed

- `Client` — its role is split between `Registry` (catalog) and `Orchestra` (execution).
- `Enum\Status` — success is `Response::isSuccess()`, the reason for failure is `ErrorInfo::$category`.
- `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException` — a failure kind is a category, not an exception class. `LlmException` (carrying `ErrorInfo`) and `LlmConfigException` remain.
- Provider `priority` and `supportedModels` — order and eligibility are described by the catalog's fallback chain.
- The dedicated `plugins` field in `Request`/`Config` — it is a special case of `extraParams`.

## [0.3.0] - 2026-07-03

Tool notes moved out of the system prompt into the tool result — to preserve the provider's prompt cache. Breaking release: `ToolInterface`/`ToolboxInterface` methods renamed, `SystemPromptComposer` removed. Migration: [UPGRADING.md](UPGRADING.md).

### Changed

- **A tool's note (`firstUseHint`) is injected into its RESULT on first use, not into the system prompt.** Previously `Agent\SystemPromptComposer` appended a notes block for every already-called tool to the system prompt and rebuilt the prompt every turn — the mutating system prefix invalidated the provider's prompt cache (OpenAI/Grok/DeepSeek/Gemini et al. cache by prefix) across the whole request history. Now the system prompt is stable across turns, and on the FIRST call of a tool in the dialogue the `Runner` puts its note directly into the JSON result under `firstUseHintKey()`. History is append-only, the request prefix is stable — the cache is reused.
  - `ToolInterface::appendToSystemPromptAfterUse()` → `ToolInterface::firstUseHint()` — same text, only WHERE it lands changed.
  - New `ToolInterface::firstUseHintKey(): string` — the key the note is stored under in the result. Defaults to `AbstractTool::DEFAULT_FIRST_USE_HINT_KEY` (`'hint_use'`), overridable per tool.
  - `AbstractTool` now defaults `firstUseHint() => ''` and `firstUseHintKey() => 'hint_use'`: a tool with no note implements nothing (`appendToSystemPromptAfterUse()` used to be mandatory).
  - `ToolboxInterface::systemPromptAddition($name)` → `ToolboxInterface::firstUseHint($name)`; added `firstUseHintKey($name)`.
  - The note is injected only when non-empty; a list result is tucked under `RunOptions::$firstUseResultKey` (`result` by default), since a list cannot take a key; with several same-named calls in one turn only the first gets it; it is not duplicated on resume/retry (first-use is decided by the earliest occurrence of the tool name in history).

### Removed

- `Agent\SystemPromptComposer` and its `TOOL_NOTES_HEADER` — the system prompt is no longer augmented per tool; `$systemPromptFn` is used as-is.

## [0.2.5] - 2026-06-23

### Added

- **Human-in-the-loop / elicitation** — a tool can pause the agent loop to wait for external input (a user's answer, an approval) and resume later. Additive and backward-compatible. New guide: [docs/13-human-in-the-loop.md](docs/13-human-in-the-loop.md).
  - `Tool\Dto\Result::suspend()` and `Result::isSuspended()` — a third tool outcome besides `ok()` / `error()`: the tool returns no result now; it will be supplied from outside.
  - `Agent\Runner` — executes the non-suspend tools of the turn normally, collects the ids of suspended tool calls (no `tool` message written for them), and stops after the turn, returning a suspended `Agent\Dto\Result` instead of calling the model again. Mixed turns are allowed: a suspend may sit alongside ordinary tools, which run as usual.
  - `Agent\Dto\Result::$suspended` (`bool`), `Agent\Dto\Result::$pendingToolCallIds` (`string[]`), and the `Agent\Dto\Result::suspended()` factory. Resume by appending one `Message::tool($id, $answer)` per pending id and calling `run()` again — the `Runner` stays stateless, there is no dedicated resume API.
  - `Agent\Runner::run()` is resumable: before each model call it completes any tool calls in the history still missing a `tool` message — ordinary tools run, suspend tools re-pause. This recovers a run interrupted mid-execution (worker crash) and makes resuming a suspended run before its answers are supplied a harmless re-pause instead of a malformed request. Re-execution is at-least-once (make side-effecting tools idempotent). The per-turn tool-execution path is factored into one method shared by the loop and resume.
  - When `maxToolCalls` runs out mid-turn, the remaining un-executed tool calls of that turn are now closed with an error `tool` message instead of left unanswered — keeping the history valid (every `tool_call` has a response) for the limit-finish request and for any later resume.
  - `Event::TOOL_CALL` is now emitted once per call when the model requests it (up front, with the assistant turn), not per execution — `executeToolCalls` emits only `TOOL_RESULT`. So re-executed calls on resume emit just the missing `TOOL_RESULT`, with no duplicate `TOOL_CALL` events.

## [0.2.4] - 2026-05-27

### Added

- `Agent\Dto\RunOptions::$extraParams` — provider-specific payload fields propagated by `Agent\Runner` into every request of the run (both the main loop turns and the limit-finish nudge). Same merge semantics as `Request::$extraParams`: standard keys (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) always win. Typical use: `$options->extraParams = ['session_id' => 'agent_42_run_17']` to group every LLM call inside one agent run under one OpenRouter session.

## [0.2.3] - 2026-05-27

### Added

- `Request::setExtraParams(array)` and the `Request::$extraParams` property — universal escape hatch for provider-specific payload fields not covered by dedicated setters (e.g. `session_id` on OpenRouter for grouping requests in observability, `user` on OpenAI for end-user tracking, `response_format`, etc.). Merged into the OpenAI-compatible payload in `OpenAiProvider::doExecute()`; standard keys (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) always win and cannot be overridden via `extraParams`.

## [0.2.1] - 2026-05-23

Documentation-only release. No code changes — drop-in replacement for 0.2.0.

### Added

- Russian translations for `README.ru.md`, `CHANGELOG.ru.md`, `UPGRADING.ru.md`, and the full `docs/ru/` mirror.
- `docs/` directory with 13 deep-dive guides: providers and fallback, logging, tools, toolbox/runner, events, history serialization, full `Config` reference, `Usage` and limits, error handling, custom HTTP client, custom provider, architecture overview, plus a trigger-based index (`docs/README.md`).

### Changed

- `README.md` restructured into a short pitch + table of links to `docs/`.
- `README.md` / `CHANGELOG.md` / `UPGRADING.md` adopt the [jonatasemidio multilanguage README pattern](https://github.com/jonatasemidio/multilanguage-readme-pattern): shield-badge language switcher, `.ru.md` suffix.
- `UPGRADING.md` trimmed to the bare migration step.

### Fixed

- ASCII flow diagram alignment in `docs/architecture.md` (and RU mirror).

## [0.2.0] - 2026-05-23

### BREAKING CHANGES

- **`ToolInterface::getSystemPromptDescription()` renamed to `ToolInterface::appendToSystemPromptAfterUse()`.** Signature and semantics unchanged; the new name reflects that the text is appended to the system prompt only after the tool has been invoked at least once. Affected files in this package:
  - `src/Tool/ToolInterface.php` — method renamed.
  - `src/Agent/AbstractToolbox.php::systemPromptAddition()` — now calls `appendToSystemPromptAfterUse()`.

  Every tool that implements `ToolInterface` or extends `AbstractTool` must rename the method. See [UPGRADING.md](UPGRADING.md).

## [0.1.0] - 2026-05-23

Initial public release.

### Added

- `Client` with priority-based provider fallback (instances or array configs).
- Three providers for OpenAI-compatible Chat Completions APIs: `OpenAiProvider`, `OpenRouterProvider`, `RequestyProvider` (all extend `BaseProvider`).
- Exponential-backoff retries (1s → 2s → 4s → ..., capped at 10s); retryable vs non-retryable via `LlmException::isRetryable()`.
- `Agent\Runner` agent loop with caps on turns and tool calls (`Agent\Dto\RunOptions`).
- Tool-calling contract: `Tool\ToolInterface`, `Tool\AbstractTool`, `Tool\SchemaBuilder`, typed `Tool\Dto\Result` (`ok($data)` / `error($message)`).
- `Agent\AbstractToolbox` with optional injected `log_message` parameter.
- `Agent\SystemPromptComposer` — augments the system prompt with per-tool notes once a tool has been used.
- `Agent\Dto\Usage` — token and LLM-call accumulator per run.
- PSR-3 `LoggerInterface` injection into `Client`; propagated to every provider built from array config.
- HTTP layer: `Http\ChatClientInterface` + ext-curl `Http\CurlChatClient`.
- DTOs (`Message`, `Request`, `Response`, `ToolCall`, `ToolDefinition`) and factories for `Message` / `ToolCall` (DTO ↔ OpenAI wire format).
- Exceptions: `LlmException`, `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException`.
- Enums: `Role`, `Status`, `Agent\Enum\Event`.

[Unreleased]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.5...v0.3.0
[0.2.5]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.1...v0.2.3
[0.2.1]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Hameleon2x/llm-orchestra/releases/tag/v0.1.0
