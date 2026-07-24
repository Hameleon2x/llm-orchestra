[![en](https://img.shields.io/badge/lang-en-red.svg)](CHANGELOG.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](CHANGELOG.ru.md)

# Changelog

Все значимые изменения `hameleon2x/llm-orchestra` фиксируются здесь. Формат: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); версионирование: [SemVer](https://semver.org/).

## [Unreleased]

## [0.4.0] - 2026-07-24

Единицей выбора стала модель, а не провайдер. Каталог моделей, одна плоская цепочка фолбэка, типизированные ошибки вместо строк, три слоя данных в ответе. Ломающий релиз без слоя совместимости: `Client` заменён на `Registry` + `Orchestra`. Миграция — [UPGRADING.ru.md](UPGRADING.ru.md).

### Добавлено

- **`Registry` — каталог провайдеров и моделей.** Провайдер описывает только транспорт (класс, токен, `baseUrl`, таймаут, заголовки); модель — ключ каталога, слаг для API (`name`), подписи (`fullName`, `description`), параметры генерации, политику ошибок и алиасы. Одна и та же модель у двух провайдеров — две записи с разными ключами, поэтому слаг больше ни с чем не путается. Каталог собирается из массива (`Registry::fromArray()`) или программно (`addProvider()`/`addModel()`) и **проверяется целиком при сборке**: несуществующий провайдер модели, опечатка в цепочке фолбэка, дублирующийся алиас — `LlmConfigException` сразу, а не в момент сбоя.
- **`Orchestra` — исполнитель запросов.** Принимает `Request` и ключ модели, применяет политику ошибок, ведёт журнал попыток. Ничего не бросает: результат — `Response` с `content` либо с `error`. Копии с переопределениями: `withPolicy()`, `withFallback()`, `withObserver()`.
- **Цепочка фолбэка — одна на каталог, плоская.** `fallback` — упорядоченный список ключей, `maxSwitches` — потолок переключений за вызов. Упавшая и уже опробованные модели пропускаются, поэтому вопроса «чей список продолжения главнее» не возникает. `then` (`fallback`/`stop`) в политике читается у стартовой модели прогона.
- **Типизированные ошибки.** `Error\ErrorCategory` (`network`, `timeout`, `empty_response`, `rate_limit`, `server_error`, `invalid_response`, `model_unavailable`, `context_length`, `content_filter`, `auth`, `bad_request`, `deadline`, `config`, `unknown`), `Error\ErrorInfo` (категория, HTTP-статус, код провайдера, ключи модели и провайдера, сырое тело, `is()`, `isConnectionDrop()`), `Error\ErrorMapper` — единственное место, где разбираются коды cURL, HTTP-статусы и тексты; им же пользуются свои провайдеры.
- **Три слоя данных в ответе.** `Response::$metadata` — наше служебное; `Response::extra()` — данные провайдера, приведённые к нашим именам картой `capture`; `Response::raw($path)` — ответ целиком с доступом по пути `choices.0.message.reasoning_content`. Новое поле у провайдера больше не требует релиза библиотеки: достаточно строки в `capture`. Встроенная карта покрывает `reasoning` (в двух написаниях), `annotations`, `refusal`, `citations`, `system_fingerprint`, имя апстрима.
- **Три уровня произвольных полей payload и заголовков:** провайдер → модель → вызов. Ассоциативные массивы сливаются рекурсивно, списки заменяются целиком, `null` удаляет ключ. Так включается «глубокое мышление» у одной модели, `reasoning_effort` у другой и `HTTP-Referer` у всего провайдера. `unsupported` вырезает параметр, который модель не принимает (`temperature` у рассуждающих), независимо от того, кто его задал.
- **`Usage` расширен:** `cachedTokens`, `reasoningTokens`, фактическая стоимость `cost` от провайдера и разбивка `byModel` — при фолбэке в прогоне участвуют модели с разной ценой. Необязательные цены каталога (`pricing`) дают оценку через `Registry::costOf()`, когда провайдер стоимость не вернул.
- **`Tool\ToolArgsGuard`** — проверка аргументов на протёкшую разметку формата вызова (`<parameter name=…>`, `<invoke …>`, теги по именам параметров). Включена по умолчанию (`Agent\Dto\Config::$toolArgsGuard`), отключается присваиванием `null`, дополняется своими паттернами. Инструмент с испорченными аргументами не исполняется — модель получает ошибку и переотправляет вызов.
- **`Agent\Enum\Finish`** — причина остановки прогона (`completed`, `tool_limit`, `turns_exhausted`, `deadline`, `error`, `suspended`) в `Agent\Dto\Result::$finish`. Раньше исходы отличались только текстом заглушки.
- **`Agent\Dto\Config::$deadlineSeconds`** — предельная длительность прогона; при исчерпании возвращается ошибка категории `deadline` с полной историей.
- **События `Event::ATTEMPT_FAILED` и `Event::MODEL_FALLBACK`** — неудачная попытка (с флагом «будет повтор» и паузой) и переключение модели. Интерфейс показывает повторы в момент, когда они происходят, не реконструируя их по журналу.
- **`Http\ChatClientInterface` принимает заголовки и таймаут вызова**, а свой клиент подставляется через конфиг провайдера (`httpClient` — объект или фабрика), без наследования провайдера.
- **`Support\SleeperInterface`** — пауза между попытками, подменяемая в тестах и в веб-контексте.
- **Отладка через PSR-3:** `'debug' => true` в конфиге провайдера пишет исходящий payload и сырой ответ на уровне `debug` (раньше это была константа в исходнике `CurlChatClient`).

### Изменено

- **Один уровень повторов вместо двух.** Транспорт больше не крутит свой цикл: повторы считает политика модели (`retries`, `delay`, `backoff`, `maxDelay`, `perCategory`, `retryOn`, `stopOn`, `maxWaitSeconds`). Время ожидания при сбое стало предсказуемым. `maxWaitSeconds` останавливает и повторы, и переключения на следующие модели цепочки; уже идущий запрос ограничивает `timeout` провайдера или модели.
- **Политика ошибок задаётся на трёх уровнях и не смешивается между ними.** Секция `policy` есть у модели и у провайдера, каталог задаёт `defaultPolicy`. Действует ближайшая заданная — модели, затем её провайдера, затем каталога — и действует целиком: незаполненные поля берут значения по умолчанию `ErrorPolicy`, а не значения соседнего уровня. Поэтому по конфигу видно, чем управляется конкретный вызов, без разбора того, что и чем перекрыто.
- **Пустой ход модели — ошибка `empty_response`, а не «успех» с заглушкой.** Раньше `Runner` возвращал текст «Нет ответа от модели.», и вызывающий код вынужден был сравнивать строки.
- **Оборванные аргументы вызова инструмента** (обрыв по лимиту токенов) распознаются как `invalid_response` — инструмент не исполняется на неполных данных.
- `Agent\Runner` работает поверх `Orchestra`; модель прогона задаётся ключом (`Config::$model`), после переключения прогон продолжается на ответившей модели (`Config::$stickyFallback`).
- `Agent\Dto\Result` несёт `ErrorInfo` вместо строки ошибки, а также ключ модели, журнал попыток и последний `Response`.
- Параметры генерации собраны в `Config\GenerationParams` (`temperature`, `topP`, `maxTokens`, `seed`) и сливаются по явности: каталог → модель → вызов.
- `Usage` переехал из `Agent\Dto` в `Dto` — он нужен и без агентского цикла.
- `Event::ASSISTANT_MESSAGE` передаёт в мете `extra` (включая размышления модели), `usage` хода и ключ модели.

### Удалено

- `Client` — его роль делят `Registry` (каталог) и `Orchestra` (исполнение).
- `Enum\Status` — успех определяется по `Response::isSuccess()`, причина сбоя по `ErrorInfo::$category`.
- `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException` — разновидность сбоя это категория, а не класс исключения. Остались `LlmException` (несёт `ErrorInfo`) и `LlmConfigException`.
- `priority` и `supportedModels` у провайдера — порядок и пригодность описывает цепочка фолбэка каталога.
- Отдельное поле `plugins` в `Request`/`Config` — это частный случай `extraParams`.

## [0.3.0] - 2026-07-03

Пояснения по тулзам переехали из системного промта в результат тулзы — ради prompt-кеша провайдера. Ломающий релиз: переименованы методы `ToolInterface`/`ToolboxInterface`, удалён `SystemPromptComposer`. Миграция — [UPGRADING.ru.md](UPGRADING.ru.md).

### Изменено

- **Пояснение по тулзе (`firstUseHint`) подмешивается в её РЕЗУЛЬТАТ при первом вызове, а не в системный промт.** Раньше `Agent\SystemPromptComposer` дописывал в system-промт блок пояснений по всем уже вызванным тулзам и пересобирал промт каждый оборот — мутирующий системный префикс сбрасывал prompt-кеш провайдера (OpenAI/Grok/DeepSeek/Gemini и др. кешируют по префиксу) на всей истории запроса. Теперь системный промт неизменен между оборотами, а `Runner` при ПЕРВОМ вызове тулзы в диалоге кладёт её пояснение прямо в JSON-результат под ключом `firstUseHintKey()`. История append-only, префикс запроса стабилен — кеш переиспользуется.
  - `ToolInterface::appendToSystemPromptAfterUse()` → `ToolInterface::firstUseHint()` — текст тот же, изменилось только КУДА он попадает.
  - Новый `ToolInterface::firstUseHintKey(): string` — имя ключа пояснения в результате. Дефолт `AbstractTool::DEFAULT_FIRST_USE_HINT_KEY` (`'hint_use'`), переопределяется в тулзе.
  - `AbstractTool` даёт дефолты `firstUseHint() => ''` и `firstUseHintKey() => 'hint_use'`: тулзе без пояснения реализовывать ничего не нужно (раньше `appendToSystemPromptAfterUse()` был обязателен).
  - `ToolboxInterface::systemPromptAddition($name)` → `ToolboxInterface::firstUseHint($name)`; добавлен `firstUseHintKey($name)`.
  - Пояснение подмешивается только если непустое; при нескольких одноимённых вызовах в одном ходе — только в первый; на возобновлении/повторе прогона не дублируется (первенство — по самому раннему вхождению имени тулзы в историю).

### Удалено

- `Agent\SystemPromptComposer` и его `TOOL_NOTES_HEADER` — системный промт больше не аугментируется по тулзам, `$systemPromptFn` используется as-is.

## [0.2.5] - 2026-06-23

### Добавлено

- **Human-in-the-loop / elicitation** — тулза может приостановить агентский цикл в ожидании внешнего ввода (ответ пользователя, апрув) и возобновиться позже. Аддитивно и обратносовместимо. Новый гайд: [docs/13-human-in-the-loop.md](docs/ru/13-human-in-the-loop.md).
  - `Tool\Dto\Result::suspend()` и `Result::isSuspended()` — третий исход тулзы помимо `ok()` / `error()`: результата сейчас нет, он поступит извне.
  - `Agent\Runner` — исполняет не-suspend тулзы хода как обычно, собирает id приостановленных вызовов (`tool`-сообщение для них не пишется) и после хода останавливается, возвращая приостановленный `Agent\Dto\Result` вместо нового обращения к модели. Смешанный ход допустим: suspend может соседствовать с обычными тулзами, которые отрабатывают штатно.
  - `Agent\Dto\Result::$suspended` (`bool`), `Agent\Dto\Result::$pendingToolCallIds` (`string[]`) и фабрика `Agent\Dto\Result::suspended()`. Возобновление — дописать по `Message::tool($id, $answer)` на каждый ожидающий id и снова вызвать `run()`; `Runner` остаётся stateless, отдельного resume-API нет.
  - `Agent\Runner::run()` теперь резюмируем: перед каждым обращением к модели он дорешивает любые tool_call'ы истории, оставшиеся без `tool`-сообщения — обычные тулзы исполняются, suspend снова приостанавливаются. Это восстанавливает прогон, прерванный посреди исполнения (краш воркера), и превращает возобновление приостановленного прогона до подачи ответов в безвредную повторную паузу вместо «битого» запроса. Перевыполнение — at-least-once (тулзы с побочками делайте идемпотентными). Путь исполнения тулз хода вынесен в один метод, общий для цикла и возобновления.
  - При исчерпании `maxToolCalls` посреди хода оставшиеся неисполненные вызовы этого хода теперь закрываются tool-ошибкой, а не остаются без ответа — история остаётся валидной (у каждого `tool_call` есть ответ) и для добивки по лимиту, и для последующего возобновления.
  - `Event::TOOL_CALL` теперь эмитится один раз на вызов, когда модель его запросила (пачкой, с ассистентским ходом), а не на каждое исполнение — `executeToolCalls` шлёт только `TOOL_RESULT`. Поэтому перезапускаемые при возобновлении вызовы эмитят лишь недостающий `TOOL_RESULT`, без дублей событий `TOOL_CALL`.

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

[Unreleased]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.5...HEAD
[0.2.5]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.4...v0.2.5
[0.2.4]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.3...v0.2.4
[0.2.3]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.1...v0.2.3
[0.2.1]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/Hameleon2x/llm-orchestra/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/Hameleon2x/llm-orchestra/releases/tag/v0.1.0
