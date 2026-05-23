**Language:** **English** · [Русский](ru/11-custom-http-client.md)

# Custom HTTP client

How to replace the cURL transport used by [`OpenAiProvider`](../src/Provider/OpenAiProvider.php) and its descendants — for tests, for Guzzle / Symfony HttpClient, or for adding middleware.

## The contract

[`ChatClientInterface`](../src/Http/ChatClientInterface.php) is one method: `chat(array $params): string` — POST `/v1/chat/completions` with `$params` as the JSON body, return the raw response body (JSON string). Throw any `Throwable` on network failure or non-2xx.

The default implementation is [`CurlChatClient`](../src/Http/CurlChatClient.php) — `ext-curl` only, no external dependencies. `OpenAiProvider::getClient()` constructs it lazily on the first request.

Reasons to swap it: unit tests (canned JSON), a different HTTP stack (Guzzle, Symfony HttpClient), middleware (logging, caching, HTTP-level retries).

## How to install your own client

`OpenAiProvider` has no public `setClient()` setter — `$client` is `protected` and lazily initialised in `getClient()`. To inject your own implementation, subclass and override `getClient()`:

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

> A public `setClient(ChatClientInterface)` on `OpenAiProvider` would remove the need for a subclass. On the wishlist as a backwards-compatible enhancement.

## Example: fake client for unit tests

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

## Example: Guzzle-backed client

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

Throw any `Throwable` on non-2xx — `OpenAiProvider::doExecute()` catches it and maps the HTTP code to the right `Llm*Exception`. Set the exception `$code` to the HTTP status so the mapping (429 → rate-limit, 4xx → validation, else → provider) picks the right branch.

## Middleware

Compose by wrapping: a `LoggingChatClient` that takes `ChatClientInterface $inner`, does its work, then delegates `chat()` to `$inner->chat()`. Inject `new LoggingChatClient(new CurlChatClient(...), $logger)` through your subclass. The same pattern works for caching, HTTP-level retries, or test recorders.

## See also

- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — where providers live in the stack.
- [docs/10-error-handling.md](10-error-handling.md) — exception mapping inside `OpenAiProvider::doExecute()`.
- [docs/12-custom-provider.md](12-custom-provider.md) — different API shape, not just a different transport.
- [docs/architecture.md](architecture.md) — the HTTP layer in context.
