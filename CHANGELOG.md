[![en](https://img.shields.io/badge/lang-en-red.svg)](CHANGELOG.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](CHANGELOG.ru.md)

# Changelog

All notable changes to `hameleon2x/llm-orchestra` are documented here. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versioning: [SemVer](https://semver.org/).

## [Unreleased]

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

- `Agent\Dto\Config::$extraParams` — provider-specific payload fields propagated by `Agent\Runner` into every request of the run (both the main loop turns and the limit-finish nudge). Same merge semantics as `Request::$extraParams`: standard keys (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) always win. Typical use: `$config->extraParams = ['session_id' => 'agent_42_run_17']` to group every LLM call inside one agent run under one OpenRouter session.

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
- `Agent\Runner` agent loop with caps on turns and tool calls (`Agent\Dto\Config`).
- Tool-calling contract: `Tool\ToolInterface`, `Tool\AbstractTool`, `Tool\SchemaBuilder`, typed `Tool\Dto\Result` (`ok($data)` / `error($message)`).
- `Agent\AbstractToolbox` with optional injected `log_message` parameter.
- `Agent\SystemPromptComposer` — augments the system prompt with per-tool notes once a tool has been used.
- `Agent\Dto\Usage` — token and LLM-call accumulator per run.
- PSR-3 `LoggerInterface` injection into `Client`; propagated to every provider built from array config.
- HTTP layer: `Http\ChatClientInterface` + ext-curl `Http\CurlChatClient`.
- DTOs (`Message`, `Request`, `Response`, `ToolCall`, `ToolDefinition`) and factories for `Message` / `ToolCall` (DTO ↔ OpenAI wire format).
- Exceptions: `LlmException`, `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException`.
- Enums: `Role`, `Status`, `Agent\Enum\Event`.

[Unreleased]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.5...HEAD
[0.2.5]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.1...v0.2.3
[0.2.1]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Hameleon2x/llm-orchestra/releases/tag/v0.1.0
