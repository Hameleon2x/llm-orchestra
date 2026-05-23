**Язык:** [English](../10-error-handling.md) · **Русский**

# Обработка ошибок

Как сбои распространяются по стеку, когда срабатывают повторы и что должен проверять ваш код.

## Иерархия исключений

Все исключения пакета наследуются от [`LlmException`](../../src/Exception/LlmException.php), у которого есть флаг `$retryable`:

| Класс                       | Типичная причина                              | `code` | `retryable` |
|-----------------------------|-----------------------------------------------|--------|-------------|
| `LlmException`              | базовое                                       | —      | флаг        |
| `LlmProviderException`      | таймаут, 5xx, битый JSON                      | разное | `true`*     |
| `LlmRateLimitException`     | HTTP 429                                      | `429`  | `true`      |
| `LlmValidationException`    | HTTP 400, 401, 403, 404 …                     | code   | `false`     |

\* `LlmProviderException` по умолчанию конструируется с `retryable=true`; `OpenAiProvider` ставит `false` для `cURL error 56` (receive failure from peer), поскольку на практике такие ошибки оказались фатальными.

## Что гарантируют Client и Runner

[`Client::execute()`](../../src/Client.php) **не бросает**. Он ловит `LlmException` и любые `Throwable` от каждого провайдера, логирует (PSR-3) и либо переходит к следующему провайдеру по цепочке fallback, либо возвращает [`Response`](../../src/Dto/Response.php), построенный через `Response::error(...)`, если все провайдеры провалились.

[`Runner::run()`](../../src/Agent/Runner.php) тоже **не бросает**. Если `Client::execute()` вернул неуспешный ответ на любом ходу, `Runner` останавливается и возвращает `Result::error(...)`. Всё, что вылетает из `ToolboxInterface::execute()`, проходит сквозь `Runner` как обычное PHP-исключение — это уже ваша забота.

Вызывающему коду хватает `if (!$response->isSuccess())` / `if (!$result->success)`.

## Как реагирует цепочка fallback

Для каждого провайдера в порядке приоритета `Client`:

1. вызывает `$provider->execute($request)` (он уже оборачивает retry-цикл `BaseProvider`);
2. при успехе — возвращает ответ;
3. при неуспешном `Response` — логирует `warning`, запоминает, пробует следующего;
4. при `LlmException` — логирует `warning`, пробует следующего;
5. при любом другом `Throwable` — логирует `error` со стектрейсом, пробует следующего.

Когда цикл закончился без успеха, `Client` возвращает последний неуспешный ответ (или синтетический `Status::ERROR`, если все провайдеры бросили исключение).

## Цикл повторов провайдера

[`BaseProvider::execute()`](../../src/Provider/BaseProvider.php) оборачивает `doExecute()` в цикл повторов, управляемый `$retryAttempts` (по умолчанию `3`): на retryable `LlmException` — спим, потом повторяем; на non-retryable — сразу сдаёмся. Backoff — **exponential backoff с потолком в 10 секунд**: 1с → 2с → 4с → 8с → 10с → 10с → …

После исчерпания всех попыток `BaseProvider` мапит последнее исключение в `Status` через `getStatusFromException()` (`RateLimit*` → `RATE_LIMIT`, `Validation*` → `VALIDATION_ERROR`, `Provider*` → `PROVIDER_ERROR`, `Timeout*` → `TIMEOUT`, иначе `ERROR`).

## Статусы ответа

Значения [`Status`](../../src/Enum/Status.php), которые могут появиться в возвращаемом `Response`:

| Константа           | Значение             | Когда                                               |
|---------------------|----------------------|-----------------------------------------------------|
| `SUCCESS`           | `'success'`          | запрос завершился штатно                            |
| `PROVIDER_ERROR`    | `'provider_error'`   | 5xx, битый ответ, общий сетевой сбой                |
| `RATE_LIMIT`        | `'rate_limit'`       | HTTP 429                                            |
| `VALIDATION_ERROR`  | `'validation_error'` | HTTP 4xx (кроме 429)                                |
| `TIMEOUT`           | `'timeout'`          | зарезервировано под таймаут-подобные исключения     |
| `ERROR`             | `'error'`            | универсальный catch-all                             |

## Разбор неудачного ответа

```php
<?php
use Hameleon2x\Llm\Enum\Status;

/** @var \Hameleon2x\Llm\Dto\Response $response */
if ($response->isSuccess()) {
    echo $response->content;
    return;
}

switch ($response->status) {
    case Status::RATE_LIMIT:        // back off; user-facing "try again later"
    case Status::VALIDATION_ERROR:  // bug in our request — do not retry, raise an alert
    case Status::PROVIDER_ERROR:
    case Status::TIMEOUT:
    case Status::ERROR:
    default:
        // every provider failed in the fallback chain
}

$errorMessage = $response->error;
$rootCause    = $response->exception;  // ?Throwable, original exception if any
$provider     = $response->provider;   // name of the provider that surfaced the error
```

Для агентских прогонов тот же статус сворачивается в `Result::$error` (string). `Result` не несёт исходный `Response::$exception` — логируйте его на уровне провайдера, если нужен.

## Ошибки выполнения тулз

Сбои тулз здесь — **не** исключения. Возвращайте `Tool\Dto\Result::error('...')`, и `Runner` сериализует его в сообщение `tool` как `{"error": "..."}`. Модель увидит это на следующем ходу и сможет отреагировать. Исключение из `Toolbox::execute()` вылетает из `Runner::run()` без изменений.

## См. также

- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — приоритет провайдеров и семантика fallback.
- [docs/03-logging.md](03-logging.md) — PSR-3 сообщения от `Client` и `BaseProvider`.
- [docs/12-custom-provider.md](12-custom-provider.md) — какое исключение бросать из вашего `doExecute()`.
