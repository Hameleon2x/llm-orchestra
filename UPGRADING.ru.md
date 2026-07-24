[![en](https://img.shields.io/badge/lang-en-red.svg)](UPGRADING.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](UPGRADING.ru.md)

# Upgrading

Ломающие изменения между мажорными версиями. Описания релизов: [CHANGELOG.ru.md](CHANGELOG.ru.md).

## 0.3.x → 0.4.x

Единица выбора — модель, а не провайдер. `Client` со списком провайдеров заменён каталогом (`Registry`) и исполнителем (`Orchestra`). Слоя совместимости нет: конфигурацию и обработку ошибок нужно переписать.

### 1. Конфигурация: провайдеры отдельно, модели отдельно

Было:

```php
$client = new Client($logger);
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => '...', 'model' => 'anthropic/claude-3.5-sonnet', 'priority' => 1],
    ['class' => OpenAiProvider::class,     'token' => '...', 'model' => 'gpt-4o-mini',                'priority' => 2],
];
```

Стало:

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

- `priority` не нужен: порядок задаёт `fallback`.
- `supportedModels` не нужен: модель привязана к своему провайдеру.
- Если модель хранится у вас в базе, переведите сохранённые слаги на ключи каталога миграцией данных: других имён у модели нет, неизвестное значение подменяется моделью по умолчанию.

### 2. Вызов: модель передаётся ключом

```php
// было
$response = $client->execute(Request::simple($system, $user));
// стало
$response = $orchestra->execute(Request::simple($system, $user), 'sonnet');   // ключ можно опустить — возьмётся defaultModel
```

Параметры генерации на вызов задаются через `Request::setTemperature()`/`setMaxTokens()` или `setParams(GenerationParams)`; поле `Request::$model` удалено.

### 3. Ошибки: категория вместо строки

```php
// было
if (!$response->isSuccess()) {
    if ($response->status === Status::RATE_LIMIT) { ... }
    log($response->error);                       // строка
}

// стало
if (!$response->isSuccess()) {
    if ($response->error->is(ErrorCategory::RATE_LIMIT)) { ... }
    if ($response->error->isConnectionDrop())            { ... }   // сеть, таймаут, пустой ход
    log($response->error->toArray());
}
```

- `Enum\Status` удалён.
- `LlmProviderException`, `LlmRateLimitException`, `LlmValidationException` удалены. Свой провайдер бросает `LlmException` с `ErrorInfo`; категорию проще получить через `ErrorMapper::fromHttpStatus()` / `fromCurl()` / `fromThrowable()`.
- Сравнения текста ошибки по подстроке уберите — для этого есть категория.

### 4. Агентский цикл

```php
$options = new RunOptions();
$options->model = 'sonnet';                    // новое: модель прогона
$options->params->temperature = 0.2;           // было: $options->temperature
$options->params->maxTokens   = 8000;          // было: $options->maxTokens
$options->extraParams = ['plugins' => [...]];  // было: $options->plugins

$result = (new Runner($orchestra))->run($messages, $toolbox, $systemPromptFn, $options, $emit);

if (!$result->success && $result->error !== null) {   // у приостановленного прогона error пуст
    echo $result->error->category;                    // было: строка $result->error
}
```

- Конструктор `Runner` принимает `Orchestra` вместо `Client`.
- Пустой ход модели больше не возвращается как успех с текстом «Нет ответа от модели.» — это ошибка категории `empty_response`. Проверки на этот текст удалите.
- Причина остановки — `Result::$finish` (`Finish::COMPLETED`, `TOOL_LIMIT`, `TURNS_EXHAUSTED`, `DEADLINE`, `ERROR`, `SUSPENDED`).
- Ручные циклы «повторить прогон при обрыве связи» уберите: повторы и переключение моделей делает `Orchestra`, а в интерфейс они приходят событиями `Event::ATTEMPT_FAILED` и `Event::MODEL_FALLBACK`.
- Проверка аргументов инструментов включена по умолчанию (`RunOptions::$toolArgsGuard`); свою такую же уберите.

### 5. Свой провайдер

Провайдер отвечает только за формат API: собрать payload, отправить, разобрать ответ. Слияние настроек, повторы и переключение моделей ушли в `Orchestra`.

```php
// было
class MyProvider extends BaseProvider
{
    // конструктор: (token, model, baseUrl, temperature, topP, maxTokens, retryAttempts, timeout, priority, supportedModels, logger)
    protected function doExecute(Request $request): Response { ... }
}

// стало — конструктор (ProviderDefinition $definition, ?LoggerInterface $logger = null)
class MyProvider extends BaseProvider
{
    protected function defaultBaseUrl(): string { return 'https://api.example.com'; }
    protected function endpointPath(): string   { return '/v1/chat/completions'; }

    public function execute(ResolvedCall $call): Response { ... }
}
```

- `ProviderInterface::execute()` принимает `ResolvedCall` вместо `Request`: параметры провайдера, модели и вызова уже слиты, слаг модели, заголовки и таймаут лежат в вызове.
- `getName()` и `getPriority()` удалены: провайдер называется своим ключом в каталоге, а порядок задаёт `fallback`.
- Публичные свойства `BaseProvider` (`$token`, `$model`, `$baseUrl`, `$temperature`, `$topP`, `$maxTokens`, `$retryAttempts`, `$timeout`, `$priority`, `$supportedModels`) и методы `doExecute()`, `getModel()`, `isModelSupported()`, `getStatusFromException()`, `sleep()` удалены — всё это либо приходит в `ResolvedCall`, либо больше не нужно.
- Ошибку провайдер бросает как `LlmException` с `ErrorInfo`, а не возвращает `Response::error()`.
- Ради одного адреса или лишних полей payload свой класс писать не нужно — хватит `baseUrl`, `headers` и `extraParams` в каталоге. Полный пример: [docs/ru/12-custom-provider.md](docs/ru/12-custom-provider.md).

### 6. Мелочи

- `Agent\Dto\Config` теперь `Agent\Dto\RunOptions`: это аргумент вызова `Runner::run()`, а не конфигурация приложения. Значения по умолчанию для всех прогонов задаются секцией `defaultRun` каталога, а `Registry::runOptions()` отдаёт готовый объект.

- `Agent\Dto\Usage` → `Dto\Usage`.
- У вызова появился потолок времени по умолчанию: `maxTotalWaitSeconds` каталога равен 600 секундам. Если у вас есть модели, которые сами отвечают дольше десяти минут, поднимите его или снимите явным `null`.
- `Response::$model` (слаг) теперь `$modelName`; появился `$modelKey` — ключ каталога, а `$provider` стал `$providerKey`.
- `Response::getTotalTokens()`, `getPromptTokens()`, `getCompletionTokens()` удалены — счётчики лежат в `$response->usage`; `getLatency()` → `latency()`; `$status` удалён (причина сбоя — категория в `$error`).
- `Response::success()` и `Response::error()` удалены: ответ собирает провайдер, для сбоя есть `Response::failed(ErrorInfo)`.
- `LlmException::isRetryable()` и `$retryable` удалены: `$e->info()->retryable`, категория — `$e->category()`.
- Поля `Request::$temperature`, `$topP`, `$maxTokens`, `$seed` собраны в `$params` (`GenerationParams`); сеттеры остались. `setPlugins()` → `setExtraParams()`.
- `RunOptions::$maxTurns` по умолчанию 40 вместо 10: лимит оборотов должен срабатывать позже лимита вызовов инструментов, иначе прогон заканчивается служебной заглушкой вместо ответа модели.
- `Agent\Dto\Result` создаётся только фабриками (`Result::success()`, `error()`, `suspended()`) — конструктор закрыт.
- Свой HTTP-клиент подставляется конфигом провайдера (`'httpClient' => $client`), а его метод принимает заголовки и таймаут: `chat(array $payload, array $headers = [], ?int $timeout = null)`.
- Отладочная константа `CurlChatClient::DEBUG` заменена на `'debug' => true` в конфиге провайдера (пишет в PSR-3).
- Конструктор `CurlChatClient` принимает готовый адрес эндпоинта первым аргументом: `(string $url, string $token, int $timeout = 120, bool $debug = false, ?LoggerInterface $logger = null)`. Раньше первым шёл токен, а путь `/v1/chat/completions` дописывал сам транспорт. Прямые вызовы `new CurlChatClient($token, $baseUrl)` нужно переписать — иначе запрос уйдёт по адресу, собранному из токена.
- Фабрика своего HTTP-клиента получает вторым аргументом готовый адрес эндпоинта: `function(ProviderDefinition $definition, string $url)`. Склеивать путь из `baseUrl` вручную больше не нужно (и `baseUrl` мог быть `null`).

## 0.2.x → 0.3.x

Пояснения по тулзам больше не дописываются в системный промт — `Runner` подмешивает их в РЕЗУЛЬТАТ тулзы при первом вызове (стабильный системный префикс → живой prompt-кеш провайдера). Переименованы методы, удалён `SystemPromptComposer`.

Переименование в твоём коде:

```
ToolInterface::appendToSystemPromptAfterUse()  →  ToolInterface::firstUseHint()
ToolboxInterface::systemPromptAddition($name)  →  ToolboxInterface::firstUseHint($name)
```

- Каждый класс, реализующий `ToolInterface` (или наследующий `AbstractTool`), обнови — иначе `Fatal error: ... contains N abstract methods`. Тулзе без пояснения метод можно вовсе убрать: `AbstractTool::firstUseHint()` теперь возвращает `''` по умолчанию.
- Своя реализация `ToolboxInterface` (не через `AbstractToolbox`): переименуй `systemPromptAddition()` в `firstUseHint()` и добавь `firstUseHintKey(string $name): string` (верни `AbstractTool::DEFAULT_FIRST_USE_HINT_KEY`, если ключ не важен).
- `Agent\SystemPromptComposer` удалён. Если ты им пользовался (например, показать «полный» системный промт в UI) — показывай просто базовый промт из `$systemPromptFn`; пояснения по тулзам теперь лежат в результатах тулз под ключом `firstUseHintKey()` (дефолт `hint_use`).
- Опционально: если дефолтный ключ `hint_use` конфликтует с полем результата тулзы — переопредели `firstUseHintKey()` в этой тулзе.

## 0.1.x → 0.2.x

`ToolInterface::getSystemPromptDescription()` переименован в `ToolInterface::appendToSystemPromptAfterUse()`. Сигнатура и семантика не изменились.

Переименование по всему проекту:

```
getSystemPromptDescription  →  appendToSystemPromptAfterUse
```

Каждый класс, реализующий `ToolInterface` (или наследующий `AbstractTool`), обновляешь сам — иначе PHP бросит `Fatal error: ... contains 1 abstract method`.
