[![en](https://img.shields.io/badge/lang-en-red.svg)](CHANGELOG.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](CHANGELOG.ru.md)

# Changelog

Все значимые изменения `hameleon2x/llm-orchestra` фиксируются здесь. Формат: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); версионирование: [SemVer](https://semver.org/).

## [Unreleased]

## [0.2.4] - 2026-05-27

### Добавлено

- `Agent\Dto\Config::$extraParams` — провайдер-специфичные поля payload, которые `Agent\Runner` пробрасывает в каждый запрос прогона (и итерации цикла, и добивку при исчерпании лимита). Семантика мерджа та же, что у `Request::$extraParams`: стандартные ключи (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) всегда выигрывают. Типовое применение: `$config->extraParams = ['session_id' => 'agent_42_run_17']` — все LLM-вызовы внутри одного запуска агента попадают в одну сессию OpenRouter.

## [0.2.3] - 2026-05-27

### Добавлено

- `Request::setExtraParams(array)` и свойство `Request::$extraParams` — универсальный механизм для провайдер-специфичных полей payload, не покрытых отдельными сеттерами (например, `session_id` у OpenRouter для группировки запросов в observability, `user` у OpenAI для трекинга конечного пользователя, `response_format` и т. п.). Сливаются в OpenAI-совместимый payload в `OpenAiProvider::doExecute()`; стандартные ключи (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `plugins`) всегда выигрывают — переопределить их через `extraParams` нельзя.

## [0.2.1] - 2026-05-23

Релиз только по документации. Кода это не касается — drop-in замена 0.2.0.

### Добавлено

- Русские переводы `README.ru.md`, `CHANGELOG.ru.md`, `UPGRADING.ru.md` и полное зеркало `docs/ru/`.
- Папка `docs/` с 13 подробными гайдами: провайдеры и fallback, логирование, тулзы, toolbox/runner, события, сериализация истории, полный справочник `Config`, `Usage` и лимиты, обработка ошибок, кастомный HTTP-клиент, кастомный провайдер, архитектурный обзор + триггер-индекс в `docs/README.md`.

### Изменено

- `README.md` пересобран в короткий питч + таблицу со ссылками на `docs/`.
- `README.md` / `CHANGELOG.md` / `UPGRADING.md` переведены на [мультиязычный README-pattern jonatasemidio](https://github.com/jonatasemidio/multilanguage-readme-pattern): shield-бейджи переключения языка, суффикс `.ru.md`.
- `UPGRADING.md` сокращён до минимально необходимого описания миграции.

### Исправлено

- Выравнивание ASCII-диаграммы потока в `docs/architecture.md` (и RU-зеркале).

## [0.2.0] - 2026-05-23

### BREAKING CHANGES

- **`ToolInterface::getSystemPromptDescription()` переименован в `ToolInterface::appendToSystemPromptAfterUse()`.** Сигнатура и семантика не изменились; новое имя отражает то, что текст добавляется в системный промт только после того, как тулзу хотя бы раз вызвали. Затронутые файлы пакета:
  - `src/Tool/ToolInterface.php` — метод переименован.
  - `src/Agent/AbstractToolbox.php::systemPromptAddition()` — теперь вызывает `appendToSystemPromptAfterUse()`.

  Каждая тулза, реализующая `ToolInterface` или наследующая `AbstractTool`, должна переименовать метод. См. [UPGRADING.ru.md](UPGRADING.ru.md).

## [0.1.0] - 2026-05-23

Первый публичный релиз.

### Добавлено

- `Client` с приоритетным fallback между провайдерами (готовые инстансы или массивы конфигов).
- Три провайдера для OpenAI-совместимых API Chat Completions: `OpenAiProvider`, `OpenRouterProvider`, `RequestyProvider` (все наследуют `BaseProvider`).
- Повторы с экспоненциальной задержкой (1s → 2s → 4s → ..., потолок 10s); retryable / non-retryable определяется через `LlmException::isRetryable()`.
- Агентский цикл `Agent\Runner` с лимитами на число ходов и вызовов тулз (`Agent\Dto\Config`).
- Контракт вызова тулз: `Tool\ToolInterface`, `Tool\AbstractTool`, `Tool\SchemaBuilder`, типизированный `Tool\Dto\Result` (`ok($data)` / `error($message)`).
- `Agent\AbstractToolbox` с опциональным внедряемым параметром `log_message`.
- `Agent\SystemPromptComposer` — дописывает в системный промт заметки по каждой тулзе после её первого вызова.
- `Agent\Dto\Usage` — накопитель токенов и LLM-вызовов за один запуск.
- PSR-3 `LoggerInterface` принимается в конструктор `Client`; пробрасывается в каждый провайдер, собираемый из массива конфига.
- HTTP-слой: `Http\ChatClientInterface` + реализация на ext-curl `Http\CurlChatClient`.
- DTO (`Message`, `Request`, `Response`, `ToolCall`, `ToolDefinition`) и фабрики для `Message` / `ToolCall` (DTO ↔ формат API OpenAI).
- Исключения: `LlmException`, `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException`.
- Перечисления: `Role`, `Status`, `Agent\Enum\Event`.

[Unreleased]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.4...HEAD
[0.2.4]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.1...v0.2.3
[0.2.1]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Hameleon2x/llm-orchestra/releases/tag/v0.1.0
