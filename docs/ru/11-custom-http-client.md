**Язык:** [English](../11-custom-http-client.md) · **Русский**

# Кастомный HTTP-клиент

Как заменить cURL-транспорт, который используют [`OpenAiProvider`](../../src/Provider/OpenAiProvider.php) и его потомки, — для тестов, для Guzzle / Symfony HttpClient или для middleware.

## Контракт

[`ChatClientInterface`](../../src/Http/ChatClientInterface.php) — один метод: `chat(array $params): string` — POST `/v1/chat/completions` с `$params` в теле как JSON, возвращает сырое тело ответа (JSON-строку). На сетевой сбой или non-2xx бросайте любой `Throwable`.

Реализация по умолчанию — [`CurlChatClient`](../../src/Http/CurlChatClient.php): только `ext-curl`, без внешних зависимостей. `OpenAiProvider::getClient()` создаёт его лениво при первом запросе.

Зачем заменять: юнит-тесты (заранее заданные JSON-ответы), другой HTTP-стек (Guzzle, Symfony HttpClient), middleware (логирование, кеширование, HTTP-уровневые повторы).

## Как подставить свой клиент

У `OpenAiProvider` нет публичного сеттера `setClient()` — `$client` объявлен как `protected` и лениво инициализируется в `getClient()`. Чтобы подсунуть свою реализацию, наследуйтесь и переопределите `getClient()`:

```php
<?php
use Hameleon2x\Llm\Http\ChatClientInterface;
use Hameleon2x\Llm\Provider\OpenAiProvider;

class TestableOpenAiProvider extends OpenAiProvider
{
    private ChatClientInterface $injected;
    public function setChatClient(ChatClientInterface $c): void { $this->injected = $c; }
    protected function getClient(): ChatClientInterface         { return $this->injected; }
}
```

> Публичный `setClient(ChatClientInterface)` на `OpenAiProvider` снял бы необходимость в подклассе. В списке желаемого — как обратно совместимое улучшение.

## Пример: фейковый клиент для юнит-тестов

```php
<?php
use Hameleon2x\Llm\Http\ChatClientInterface;

final class FakeChatClient implements ChatClientInterface
{
    /** @var string[] */ private array $responses;
    /** @var array<int, array> */ public array $sentParams = [];

    public function __construct(array $responses) { $this->responses = $responses; }

    public function chat(array $params): string
    {
        $this->sentParams[] = $params;
        if ($this->responses === []) {
            throw new \RuntimeException('FakeChatClient: no more canned responses');
        }
        return array_shift($this->responses);
    }
}

// Wire-up: build a canned chat-completions JSON, inject through the subclass,
// then call $client->execute() and assert on $response->content / $fake->sentParams.
$fake = new FakeChatClient([json_encode([
    'model'   => 'gpt-4o-mini',
    'choices' => [['message' => ['role' => 'assistant', 'content' => 'pong'], 'finish_reason' => 'stop']],
    'usage'   => ['prompt_tokens' => 4, 'completion_tokens' => 1, 'total_tokens' => 5],
])]);
$provider = new TestableOpenAiProvider('test-token', 'gpt-4o-mini');
$provider->setChatClient($fake);
```

## Пример: клиент на базе Guzzle

```php
<?php
use GuzzleHttp\ClientInterface;
use Hameleon2x\Llm\Http\ChatClientInterface;

final class GuzzleChatClient implements ChatClientInterface
{
    private ClientInterface $http; private string $token; private string $baseUrl;
    public function __construct(ClientInterface $http, string $token, string $baseUrl = 'https://api.openai.com')
    { $this->http = $http; $this->token = $token; $this->baseUrl = $baseUrl; }

    public function chat(array $params): string
    {
        $params['stream'] = false;
        $resp = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'        => json_encode($params, JSON_UNESCAPED_UNICODE),
            'http_errors' => true,  // Guzzle throws on non-2xx — that's the contract
        ]);
        return (string)$resp->getBody();
    }
}
```

Бросайте любой `Throwable` на non-2xx — `OpenAiProvider::doExecute()` ловит его и мапит HTTP-код в нужный `Llm*Exception`. Задавайте `$code` исключения равным HTTP-статусу, чтобы маппинг (429 → rate-limit, 4xx → validation, иначе → provider) выбрал правильную ветку.

## Middleware

Композиция через обёртки: `LoggingChatClient`, принимающий `ChatClientInterface $inner` в конструкторе, делает своё дело и делегирует `chat()` в `$inner->chat()`. Подсовывайте `new LoggingChatClient(new CurlChatClient(...), $logger)` через подкласс. Тот же паттерн работает для кеширования, HTTP-уровневых повторов или тест-рекордеров.

## См. также

- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — где провайдеры живут в стеке.
- [docs/10-error-handling.md](10-error-handling.md) — маппинг исключений внутри `OpenAiProvider::doExecute()`.
- [docs/12-custom-provider.md](12-custom-provider.md) — иной формат API, не только иной транспорт.
- [docs/architecture.md](architecture.md) — HTTP-слой в контексте.
