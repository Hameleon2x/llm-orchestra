**Язык:** [English](../02-providers-and-fallback.md) · **Русский**

# Провайдеры и fallback

Как зарегистрировать несколько LLM-провайдеров, управлять порядком, в котором `Client` их пробует, и какие ошибки приводят к повтору, а какие — к переходу к следующему провайдеру.

## Встроенные провайдеры

Все три провайдера работают через одну и ту же OpenAI-совместимую Chat Completions API и наследуются от `OpenAiProvider` → `BaseProvider`. Различаются только дефолтным `baseUrl`, дефолтной `model` и `getName()`.

| Класс                                          | `baseUrl` по умолчанию       | `model` по умолчанию                  | `getName()`  |
|------------------------------------------------|------------------------------|---------------------------------------|--------------|
| `Hameleon2x\Llm\Provider\OpenAiProvider`       | `https://api.openai.com`     | `gpt-4o-mini`                         | `OpenAI`     |
| `Hameleon2x\Llm\Provider\OpenRouterProvider`   | `https://openrouter.ai/api`  | `deepseek/deepseek-chat-v3-0324:free` | `OpenRouter` |
| `Hameleon2x\Llm\Provider\RequestyProvider`     | `https://router.requesty.ai` | `openai/gpt-4.1-mini`                 | `Requesty`   |

Суффикс `/v1/chat/completions` добавляется самим `CurlChatClient` — передавай `baseUrl` без `/v1`.

## Ключи конфига

Каждая запись в `$client->providers` — это либо готовый экземпляр `ProviderInterface`, либо массив-конфиг, который разворачивает `Client::createProvider()`:

| Ключ              | Тип            | По умолчанию                         | Назначение                                                            |
|-------------------|----------------|--------------------------------------|------------------------------------------------------------------------|
| `class`           | class-string   | обязательно                          | Класс провайдера для инстанцирования.                                  |
| `token`           | string         | обязательно                          | API-токен.                                                             |
| `model`           | string         | обязательно                          | Дефолтная модель для этого провайдера.                                 |
| `baseUrl`         | ?string        | специфично для провайдера            | Переопределить upstream URL (прокси, self-hosted шлюзы).               |
| `temperature`     | ?float         | `Client::$defaultTemperature` (0.7)  | Температура генерации.                                                 |
| `topP`            | ?float         | `Client::$defaultTopP` (0.95)        | Top-p сэмплинг.                                                        |
| `maxTokens`       | ?int           | `Client::$defaultMaxTokens` (1024)   | Лимит токенов ответа.                                                  |
| `retryAttempts`   | int            | 3                                    | Сколько раз повторять для retryable-ошибок.                            |
| `timeout`         | int            | 30                                   | HTTP-таймаут (секунды).                                                |
| `priority`        | int            | 999                                  | Меньше число — раньше очередь.                                         |
| `supportedModels` | ?string[]      | `null`                               | Подстроки; если `model` запроса не совпадает ни с одной — провайдер пропускается. |

Напрямую собранный провайдер (`new OpenAiProvider(...)`) принимает те же параметры в том же порядке через конструктор.

## Порядок fallback: `priority`

`Client::execute()` сортирует провайдеров по возрастанию `priority` и пробует их по очереди. Побеждает первый, у кого `Response::isSuccess() === true`. Провайдер пропускается, и `Client` переходит к следующему, когда:

1. Бросает non-retryable `LlmException` или исчерпывает `retryAttempts`.
2. Возвращает `Response` со `status !== SUCCESS`.
3. Бросает любой другой `Throwable` (логируется на уровне `error`).

```php
<?php
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Provider\OpenRouterProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => 'sk-or-...', 'model' => 'anthropic/claude-3.5-sonnet', 'priority' => 1], // primary
    ['class' => OpenAiProvider::class,     'token' => 'sk-...',     'model' => 'gpt-4o-mini',                'priority' => 2], // backup
];

$response = $client->execute(Request::simple('You are concise.', 'Hi.'));
echo $response->provider; // 'OpenRouter' or 'OpenAI' depending on which one answered
```

Если все провайдеры упали, возвращается последний неуспешный `Response` (так что `status`/`error` отражают последнюю попытку). Если список пуст — получишь синтетический `Response::error(Status::ERROR, 'all', 'none', ...)`.

## Повторы внутри провайдера

`BaseProvider::execute()` оборачивает каждый запрос в цикл повторов:

- До `retryAttempts` попыток (по умолчанию 3).
- Exponential backoff между попытками: 1с, 2с, 4с, 8с, потолок 10с.
- Повторяются только retryable-ошибки. Non-retryable сразу прерывают цикл, чтобы `Client` мог перейти к следующему провайдеру.

Retryable-флаг зашит в самом исключении:

| Исключение                 | Retryable | Триггеры                                                                |
|----------------------------|-----------|-------------------------------------------------------------------------|
| `LlmRateLimitException`    | да        | HTTP 429 или payload ошибки с `code === 429`.                           |
| `LlmProviderException`     | да (по умолчанию) | Сетевые ошибки, 5xx, битый JSON, пустые ответы. cURL error 56 помечен как non-retryable. |
| `LlmValidationException`   | нет       | HTTP 4xx кроме 429; либо `model` не входит в `supportedModels`.         |

После выхода из цикла провайдер возвращает `Response::error(...)` со `status`, выведенным из класса последнего исключения (`RATE_LIMIT`, `VALIDATION_ERROR`, `PROVIDER_ERROR`, `TIMEOUT`, `ERROR`).

## Пропуск провайдеров по модели: `supportedModels`

Когда запрос фиксирует конкретную `model` через `Request::setModel('...')`, каждый провайдер сверяет имя с `supportedModels` (поиск по подстроке). Промах поднимает `LlmValidationException` (non-retryable), и `Client` сразу переходит к следующему провайдеру.

```php
$client->providers = [
    [
        'class'           => OpenAiProvider::class,
        'token'           => 'sk-...',
        'model'           => 'gpt-4o-mini',
        'supportedModels' => ['gpt-', 'o1-', 'o3-'],
        'priority'        => 1,
    ],
    [
        'class'           => OpenRouterProvider::class,
        'token'           => 'sk-or-...',
        'model'           => 'anthropic/claude-3.5-sonnet',
        'supportedModels' => null, // accept anything
        'priority'        => 2,
    ],
];

// Asking for a Claude model — OpenAI is skipped, OpenRouter handles it.
$request = Request::simple('be brief', 'hi')->setModel('anthropic/claude-3.5-sonnet');
$response = $client->execute($request);
```

`supportedModels = null` (по умолчанию) означает «принимаем что угодно».

## Таймаут

`timeout` — таймаут cURL-запроса в секундах; connect timeout считается как `min(30, $timeout)`. Срыв по таймауту всплывает как `LlmProviderException` (retryable) внутри цикла провайдера.

## См. также

- [01-getting-started.md](01-getting-started.md) — быстрый старт с одним провайдером.
- [03-logging.md](03-logging.md) — наблюдение за повторами и переходами через PSR-3.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — как `Runner` использует `Client`.
