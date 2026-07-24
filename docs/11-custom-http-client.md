**Language:** **English** · [Русский](ru/11-custom-http-client.md)

# Custom HTTP client

By default requests go through `Http\CurlChatClient` — an `ext-curl` implementation with no external dependencies. Replace the transport when you need your application's HTTP client (PSR-18, Guzzle), a corporate proxy, or recorded fixtures in tests.

## Interface

```php
interface ChatClientInterface
{
    /**
     * @param array                 $payload request body
     * @param array<string, string> $headers extra headers on top of the mandatory ones
     * @param int|null              $timeout request timeout in seconds; null — the client's own timeout
     * @return string raw response body
     * @throws LlmException on transport failure or a response with an error code
     */
    public function chat(array $payload, array $headers = [], ?int $timeout = null): string;
}
```

The client is responsible only for sending the request and mapping transport failures to `LlmException`. Parsing the response, retries, and model switching are not its job.

## Wiring

A ready-made object or a factory is specified in the provider config:

```php
'providers' => [
    'openai' => [
        'class'      => OpenAiProvider::class,
        'token'      => 'sk-...',
        'httpClient' => $myClient,                                   // an object
    ],
    'openrouter' => [
        'class'      => OpenRouterProvider::class,
        'token'      => 'sk-or-...',
        'httpClient' => fn(ProviderDefinition $def, string $url) => new MyClient($url, $def->token),
    ],
],
```

The factory receives a `ProviderDefinition` (token, timeout, the `debug` flag) and the ready endpoint URL as its second argument: the provider owns the path, so there is nothing to assemble by hand.

## Let's start simple: a client for tests

The most common reason to replace the transport is testing. The client hands back pre-prepared responses and remembers what it was asked for:

```php
<?php

use Hameleon2x\Llm\Http\ChatClientInterface;

final class FakeChatClient implements ChatClientInterface
{
    /** @var string[] queued responses */
    private array $queue;

    /** @var array[] what was sent */
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

It's wired in the same way as any other client:

```php
$registry = Registry::fromArray([
    'providers' => [
        'test' => ['class' => OpenAiProvider::class, 'httpClient' => new FakeChatClient([$json1, $json2])],
    ],
    'models' => ['m' => ['provider' => 'test', 'name' => 'test-model']],
]);
```

Together with `Support\SleeperInterface` (a no-op pause instead of `usleep`), this lets you run retry and model-switching scenarios instantly:

```php
$orchestra = new Orchestra($registry, null, new class implements SleeperInterface {
    public function sleep(float $seconds): void {}
});
```

## Example: PSR-18

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

`ErrorMapper` gives you a ready-made category from the HTTP status, the cURL code, or the exception — no need to classify failures yourself.

## See also

- [12-custom-provider.md](12-custom-provider.md) — when you need a different API format, not a different transport.
- [10-error-handling.md](10-error-handling.md) — categories and `ErrorMapper`.
