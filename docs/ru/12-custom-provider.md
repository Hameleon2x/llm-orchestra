**Язык:** [English](../12-custom-provider.md) · **Русский**

# Свой провайдер

Провайдер отвечает за формат API: собрать payload, отправить его и разобрать ответ. Всё остальное — слияние настроек, повторы, переключение моделей, журнал попыток — делает `Orchestra`, поэтому свой провайдер обычно занимает несколько десятков строк.

## Когда он нужен

- API не OpenAI-совместимый (собственный формат сообщений или инструментов).
- Нужен другой разбор ответа.
- Локальная модель со своим протоколом.

Если API OpenAI-совместимый, а отличается только адрес — провайдер писать не нужно: хватит `baseUrl` в конфиге. Если отличаются заголовки или дополнительные поля payload — тоже: для этого есть `headers` и `extraParams` (см. [02-catalog-and-fallback.md](02-catalog-and-fallback.md)).

## Контракт

```php
interface ProviderInterface
{
    public function execute(ResolvedCall $call): Response;   // throws LlmException
}
```

Исполнитель создаёт провайдера как `new $class($definition, $logger)` — конструктор с такой сигнатурой обязателен. Проще всего наследовать `BaseProvider`: он даёт конструктор, доступ к настройкам, HTTP-клиент и применение карты `capture`.

## Что приходит в `ResolvedCall`

Слияние трёх уровней конфигурации уже выполнено — провайдеру остаётся взять готовое:

```php
$call->request;            // сообщения, инструменты, toolChoice
$call->modelName();        // слаг модели для API
$call->modelKey();         // ключ модели каталога — его нужно положить в Response
$call->providerKey();      // ключ провайдера каталога
$call->paramsPayload();    // temperature, top_p, max_tokens, seed — без неподдерживаемых моделью
$call->extraParams;        // дополнительные поля payload: провайдер + модель + вызов
$call->headers;            // заголовки: провайдер + модель + вызов
$call->timeout;            // таймаут запроса, секунды
$call->capture;            // карта извлечения полей ответа
$call->keepRaw;            // класть ли сырой ответ в Response
```

## Минимальный провайдер

```php
<?php

use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Dto\Usage;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Factory\MessageFactory;
use Hameleon2x\Llm\Provider\BaseProvider;
use Hameleon2x\Llm\Support\ArrayPath;

final class MyProvider extends BaseProvider
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /** Путь эндпоинта: формат API знает провайдер, транспорт получает готовый адрес. */
    protected function endpointPath(): string
    {
        return '/v1/chat';
    }

    /** Поля ответа, которые приложение получит через $response->extra(). */
    protected function defaultCapture(): array
    {
        return ['reasoning' => 'result.thinking'];
    }

    public function execute(ResolvedCall $call): Response
    {
        $payload = $call->extraParams + [
            'model'    => $call->modelName(),
            'messages' => array_map(
                static fn($message) => MessageFactory::toArray($message),
                $call->request->messages
            ),
        ];
        $payload += $call->paramsPayload();

        $startedAt = microtime(true);
        $body = $this->client()->chat($payload, $call->headers, $call->timeout);
        $latency = microtime(true) - $startedAt;

        $raw = json_decode($body, true);
        if (!is_array($raw)) {
            throw new LlmException(new ErrorInfo(ErrorCategory::INVALID_RESPONSE, 'Ответ не разбирается как JSON.'));
        }

        $content = (string)ArrayPath::get($raw, 'result.text', '');
        if (trim($content) === '') {
            throw new LlmException(new ErrorInfo(ErrorCategory::EMPTY_RESPONSE, 'Модель вернула пустой ответ.'));
        }

        $usage = new Usage();
        $usage->calls = 1;
        $usage->promptTokens = (int)ArrayPath::get($raw, 'result.tokens.in', 0);
        $usage->completionTokens = (int)ArrayPath::get($raw, 'result.tokens.out', 0);
        $usage->totalTokens = $usage->promptTokens + $usage->completionTokens;

        $response = new Response();
        $response->content = $content;
        $response->usage = $usage;
        $response->modelKey = $call->modelKey();
        $response->modelName = $call->modelName();
        $response->providerKey = $call->providerKey();
        $response->extra = $this->capture($raw, $call);
        $response->metadata = ['latency' => $latency];
        $response->setRaw($call->keepRaw ? $raw : null);

        return $response;
    }
}
```

Регистрация — как у любого другого транспорта:

```php
'providers' => [
    'mine' => ['class' => MyProvider::class, 'token' => '...'],
],
'models' => [
    'my-model' => ['provider' => 'mine', 'name' => 'model-v1'],
],
```

Каталог проверяет, что класс существует и реализует `ProviderInterface`, ещё при сборке.

## Ошибки

Провайдер бросает `LlmException` с `ErrorInfo`. Категорию удобно получить из `ErrorMapper`, если сбой пришёл по HTTP или через исключение:

```php
throw new LlmException(ErrorMapper::fromHttpStatus($status, $body, $decoded));
throw new LlmException(ErrorMapper::fromThrowable($e), $e);
throw LlmException::of(ErrorCategory::CONTENT_FILTER, 'Ответ заблокирован модерацией.');
```

Важное: **повторы внутри провайдера не делаются**. Уровень повторов ровно один — политика модели в `Orchestra`; собственный цикл сделал бы время ожидания непредсказуемым.

Правильно классифицировать стоит хотя бы три ситуации, иначе повторы и фолбэк будут работать вслепую:

- транспортный сбой и таймаут — `ErrorMapper::fromCurl()` или `fromThrowable()`;
- ответ без содержимого — `EMPTY_RESPONSE`;
- ответ, который не разбирается, — `INVALID_RESPONSE`.

## Наследование OpenAiProvider

Если API OpenAI-совместимый, но с особенностями разбора, наследуйте `OpenAiProvider` и переопределяйте точечно: `defaultBaseUrl()`, `endpointPath()`, `defaultCapture()`, `buildPayload()`, `parse()`, `parseUsage()`, `parseToolCalls()`, `normalizeContent()`. Так сделаны `OpenRouterProvider` и `RequestyProvider` — у них отличается только базовый URL.

## См. также

- [11-custom-http-client.md](11-custom-http-client.md) — если нужен другой транспорт, а не другой формат API.
- [10-error-handling.md](10-error-handling.md) — категории ошибок.
- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — как провайдер описывается в каталоге.
