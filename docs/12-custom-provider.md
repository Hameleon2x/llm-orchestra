**Language:** **English** · [Русский](ru/12-custom-provider.md)

# Custom provider

How to add a provider for an API that is **not** OpenAI-compatible (e.g. Anthropic Messages API, a private corporate gateway). For OpenAI-compatible endpoints, set `baseUrl` on `OpenAiProvider` instead — no new class needed.

## What `BaseProvider` gives you

Extend [`BaseProvider`](../src/Provider/BaseProvider.php) and you get for free: the retry loop with 1s→2s→4s→8s (cap 10s) backoff, automatic `metadata.latency` / `metadata.attempt`, exception → `Status` mapping, the `$supportedModels` allowlist, and generation-param helpers `getTemperature/getTopP/getMaxTokens/getModel($request, $default)`.

## What you must implement

```php
abstract protected function doExecute(Request $request): Response;
```

Also override `getName()` to a short stable string — it appears in PSR-3 logs and in `Response::$provider`.

## Translation rules

`Request` is provider-agnostic. In `doExecute()` translate `messages` / `tools` (use [`MessageFactory::toArray()`](../src/Factory/MessageFactory.php) and [`ToolDefinitionFactory::toArray()`](../src/Factory/ToolDefinitionFactory.php) when shapes are close, otherwise hand-roll); use `getTemperature/getTopP/getMaxTokens($request, $default)` for generation params; use `getModel($request)` (it enforces the allowlist); pass through `seed`, `plugins`, `toolChoice` if supported.

On `Response::success(...)` populate `provider`, `model`, `content` (or `null` for tool-only), `toolCalls`, and `metadata` with `promptTokens` / `completionTokens` / `totalTokens` / `finishReason`. `metadata.latency` and `metadata.attempt` are added by `BaseProvider` — do not set them.

## Which exception to throw

`429` → `LlmRateLimitException` (retryable); other `4xx` → `LlmValidationException` (not retryable); `5xx` / timeout / decode error → `LlmProviderException` (retryable by default; pass `$retryable = false` for known-fatal network failures). Do not catch and convert to `Response` inside `doExecute()` — throw, and let `BaseProvider`'s retry loop do its job.

## Skeleton: AnthropicProvider

A sketch — not a full implementation. Anthropic uses a different envelope: `role: user/assistant` only (no `tool` role); tool results live inside `user` messages as `tool_result` blocks; assistant tool calls are content blocks of type `tool_use`.

```php
<?php
use Hameleon2x\Llm\Dto\{Request, Response, ToolCall};
use Hameleon2x\Llm\Exception\{LlmProviderException, LlmRateLimitException, LlmValidationException};
use Hameleon2x\Llm\Provider\BaseProvider;

final class AnthropicProvider extends BaseProvider
{
    public function getName(): string { return 'Anthropic'; }

    protected function doExecute(Request $request): Response
    {
        $payload = [
            'model'       => $this->getModel($request),
            'max_tokens'  => $this->getMaxTokens($request, 1024),
            'temperature' => $this->getTemperature($request),
            'messages'    => $this->mapMessages($request),  // TODO; also 'system' + 'tools'
        ];

        try {
            // TODO: POST {$this->baseUrl}/v1/messages with x-api-key + anthropic-version.
            // Throw RuntimeException with the HTTP code on non-2xx.
            $raw = $this->httpPost('/v1/messages', $payload);
        } catch (\Throwable $e) {
            $code = (int)$e->getCode();
            if ($code === 429)               { throw new LlmRateLimitException($e->getMessage(), $code, $e); }
            if ($code >= 400 && $code < 500) { throw new LlmValidationException($e->getMessage(), $code, $e); }
            throw new LlmProviderException('Anthropic request failed: ' . $e->getMessage(), $code, $e);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LlmProviderException('Anthropic: invalid JSON response', 0, null, true);
        }

        // Anthropic returns an array of content blocks; collect text + tool_use into our shape.
        $content = ''; $toolCalls = [];
        foreach ($decoded['content'] ?? [] as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'text') {
                $content .= $block['text'] ?? '';
            } elseif ($type === 'tool_use') {
                $toolCalls[] = new ToolCall((string)($block['id'] ?? ''), 'function', [
                    'name'      => (string)($block['name'] ?? ''),
                    'arguments' => json_encode($block['input'] ?? new \stdClass()),
                ]);
            }
        }

        $u = $decoded['usage'] ?? [];
        return Response::success($this->getName(), (string)($decoded['model'] ?? $this->getModel($request)), $content !== '' ? $content : null, $toolCalls, [
            'promptTokens'     => (int)($u['input_tokens']  ?? 0),
            'completionTokens' => (int)($u['output_tokens'] ?? 0),
            'totalTokens'      => (int)(($u['input_tokens'] ?? 0) + ($u['output_tokens'] ?? 0)),
            'finishReason'     => $decoded['stop_reason'] ?? null,
        ]);
    }

    private function mapMessages(Request $request): array { /* TODO: roles, system out, tool_result blocks */ return []; }
    private function mapTools(array $tools): array        { /* TODO: name/description/input_schema */ return []; }
    private function httpPost(string $path, array $body): string { throw new \RuntimeException('not implemented', 0); }
}
```

## Registering the provider

Same shape as the built-in providers — `Client` instantiates the class from the config array (see [`Client::createProvider()`](../src/Client.php)):

```php
$client = new Client($logger);
$client->providers = [
    ['class' => App\Llm\Provider\AnthropicProvider::class, 'token' => getenv('ANTHROPIC_API_KEY'), 'model' => 'claude-3-5-sonnet-latest', 'priority' => 1],
    ['class' => Hameleon2x\Llm\Provider\OpenAiProvider::class, 'token' => getenv('OPENAI_API_KEY'),    'model' => 'gpt-4o-mini',              'priority' => 2],
];
```

The factory honours: `class`, `token`, `model`, `baseUrl`, `temperature`, `topP`, `maxTokens`, `retryAttempts`, `timeout`, `priority`, `supportedModels`. The PSR-3 logger from `Client` is injected automatically. If your provider takes extra constructor arguments, instantiate it yourself and call `$client->addProvider($instance)` instead.

## See also

- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — priority and fallback semantics.
- [docs/10-error-handling.md](10-error-handling.md) — what each exception costs you.
- [docs/11-custom-http-client.md](11-custom-http-client.md) — swap only the transport, not the API shape.
- [docs/architecture.md](architecture.md) — where the provider layer fits.
