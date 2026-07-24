**Язык:** [English](../11-custom-http-client.md) · **Русский**

# Свой HTTP-клиент

По умолчанию запросы уходят через `Http\CurlChatClient` — реализацию на `ext-curl` без внешних зависимостей. Подменить транспорт стоит, когда нужен HTTP-клиент приложения (PSR-18, Guzzle), корпоративный прокси или запись фикстур в тестах.

## Интерфейс

```php
interface ChatClientInterface
{
    /**
     * @param array                 $payload тело запроса
     * @param array<string, string> $headers дополнительные заголовки поверх обязательных
     * @param int|null              $timeout таймаут запроса в секундах; null — таймаут клиента
     * @return string сырое тело ответа
     * @throws LlmException при сбое транспорта или ответе с кодом ошибки
     */
    public function chat(array $payload, array $headers = [], ?int $timeout = null): string;
}
```

Клиент отвечает только за отправку и за приведение транспортных сбоев к `LlmException`. Разбор ответа, повторы и переключение моделей — не его дело.

## Подключение

Готовый объект или фабрика указываются в конфиге провайдера:

```php
'providers' => [
    'openai' => [
        'class'      => OpenAiProvider::class,
        'token'      => 'sk-...',
        'httpClient' => $myClient,                                   // объект
    ],
    'openrouter' => [
        'class'      => OpenRouterProvider::class,
        'token'      => 'sk-or-...',
        'httpClient' => fn(ProviderDefinition $def, string $url) => new MyClient($url, $def->token),
    ],
],
```

Фабрика получает `ProviderDefinition` (токен, таймаут, флаг `debug`) и готовый адрес эндпоинта вторым аргументом: путь знает провайдер, собирать его вручную не нужно.

## Начнём с простого: клиент для тестов

Самая частая причина подменить транспорт — тесты. Клиент отдаёт заранее заготовленные ответы и запоминает, что у него просили:

```php
<?php

use Hameleon2x\Llm\Http\ChatClientInterface;

final class FakeChatClient implements ChatClientInterface
{
    /** @var string[] очередь ответов */
    private array $queue;

    /** @var array[] что было отправлено */
    public array $sent = [];

    public function __construct(array $queue)
    {
        $this->queue = $queue;
    }

    public function chat(array $payload, array $headers = [], ?int $timeout = null): string
    {
        $this->sent[] = ['payload' => $payload, 'headers' => $headers];

        return array_shift($this->queue) ?? '{}';
    }
}
```

Подключается он так же, как любой другой:

```php
$registry = Registry::fromArray([
    'providers' => [
        'test' => ['class' => OpenAiProvider::class, 'httpClient' => new FakeChatClient([$json1, $json2])],
    ],
    'models' => ['m' => ['provider' => 'test', 'name' => 'test-model']],
]);
```

Вместе с `Support\SleeperInterface` (пауза-заглушка вместо `usleep`) это позволяет прогонять сценарии повторов и переключения моделей мгновенно:

```php
$orchestra = new Orchestra($registry, null, new class implements SleeperInterface {
    public function sleep(float $seconds): void {}
});
```

## Пример: PSR-18

```php
<?php

use Hameleon2x\Llm\Error\ErrorMapper;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Http\ChatClientInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr18ChatClient implements ChatClientInterface
{
    private ClientInterface $client;
    private RequestFactoryInterface $requests;
    private StreamFactoryInterface $streams;
    private string $url;
    private string $token;

    public function __construct(
        ClientInterface $client,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        string $url,
        string $token
    ) {
        $this->client = $client;
        $this->requests = $requests;
        $this->streams = $streams;
        $this->url = $url;
        $this->token = $token;
    }

    public function chat(array $payload, array $headers = [], ?int $timeout = null): string
    {
        $request = $this->requests->createRequest('POST', $this->url)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer ' . $this->token)
            ->withBody($this->streams->createStream(json_encode($payload, JSON_UNESCAPED_UNICODE)));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader((string)$name, (string)$value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new LlmException(ErrorMapper::fromThrowable($e), $e);
        }

        $body = (string)$response->getBody();
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $decoded = json_decode($body, true);
            throw new LlmException(ErrorMapper::fromHttpStatus($status, $body, is_array($decoded) ? $decoded : null));
        }

        return $body;
    }
}
```

`ErrorMapper` даёт готовую категорию по HTTP-статусу, коду cURL или исключению — классифицировать сбои заново не нужно.

## См. также

- [12-custom-provider.md](12-custom-provider.md) — если нужен не другой транспорт, а другой формат API.
- [10-error-handling.md](10-error-handling.md) — категории и `ErrorMapper`.
