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
- Если ключ модели хранится у вас в базе, положите прежние слаги в `aliases` — старые значения отрезолвятся в новый ключ без миграции данных.

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
$config = new Config();
$config->model = 'sonnet';                    // новое: модель прогона
$config->params->temperature = 0.2;           // было: $config->temperature
$config->params->maxTokens   = 8000;          // было: $config->maxTokens
$config->extraParams = ['plugins' => [...]];  // было: $config->plugins

$result = (new Runner($orchestra))->run($messages, $toolbox, $systemPromptFn, $config, $emit);

if (!$result->success) {
    echo $result->error->category;            // было: строка $result->error
}
```

- Конструктор `Runner` принимает `Orchestra` вместо `Client`.
- Пустой ход модели больше не возвращается как успех с текстом «Нет ответа от модели.» — это ошибка категории `empty_response`. Проверки на этот текст удалите.
- Причина остановки — `Result::$finish` (`Finish::COMPLETED`, `TOOL_LIMIT`, `TURNS_EXHAUSTED`, `DEADLINE`, `ERROR`, `SUSPENDED`).
- Ручные циклы «повторить прогон при обрыве связи» уберите: повторы и переключение моделей делает `Orchestra`, а в интерфейс они приходят событиями `Event::ATTEMPT_FAILED` и `Event::MODEL_FALLBACK`.
- Проверка аргументов инструментов включена по умолчанию (`Config::$toolArgsGuard`); свою такую же уберите.

### 5. Мелочи

- `Agent\Dto\Usage` → `Dto\Usage`.
- `Response::$model` (слаг) теперь `$modelName`; появился `$modelKey` — ключ каталога.
- Свой HTTP-клиент подставляется конфигом провайдера (`'httpClient' => $client`), а его метод принимает заголовки и таймаут: `chat(array $payload, array $headers = [], ?int $timeout = null)`.
- Отладочная константа `CurlChatClient::DEBUG` заменена на `'debug' => true` в конфиге провайдера (пишет в PSR-3).

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
