**Язык:** [English](../12-custom-provider.md) · **Русский**

# Кастомный провайдер

Как добавить провайдер для API, **не** совместимого с OpenAI (например, Anthropic Messages API, частный корпоративный шлюз). Для OpenAI-совместимых эндпоинтов задавайте `baseUrl` на `OpenAiProvider` — новый класс не нужен.

## Что даёт `BaseProvider`

Унаследуйтесь от [`BaseProvider`](../../src/Provider/BaseProvider.php) — и бесплатно получите: цикл повторов с backoff 1с → 2с → 4с → 8с (потолок 10с), автоматические `metadata.latency` / `metadata.attempt`, маппинг исключений в `Status`, allowlist `$supportedModels` и хелперы для параметров генерации `getTemperature/getTopP/getMaxTokens/getModel($request, $default)`.

## Что нужно реализовать

```php
abstract protected function doExecute(Request $request): Response;
```

Заодно переопределите `getName()` коротким стабильным значением — оно появляется в PSR-3 логах и в `Response::$provider`.

## Правила перевода

`Request` провайдер-агностичен. В `doExecute()` переводите `messages` / `tools` (используйте [`MessageFactory::toArray()`](../../src/Factory/MessageFactory.php) и [`ToolDefinitionFactory::toArray()`](../../src/Factory/ToolDefinitionFactory.php), когда формы близки, иначе пишите руками); используйте `getTemperature/getTopP/getMaxTokens($request, $default)` для параметров генерации; используйте `getModel($request)` (он применяет allowlist); пробрасывайте `seed`, `plugins`, `toolChoice`, если поддерживаются.

В `Response::success(...)` заполните `provider`, `model`, `content` (или `null` для случая «только tool-calls»), `toolCalls` и `metadata` с `promptTokens` / `completionTokens` / `totalTokens` / `finishReason`. `metadata.latency` и `metadata.attempt` добавляет `BaseProvider` сам — не задавайте их вручную.

## Какое исключение бросать

`429` → `LlmRateLimitException` (retryable); другие `4xx` → `LlmValidationException` (non-retryable); `5xx` / таймаут / ошибка декодирования → `LlmProviderException` (по умолчанию retryable; передавайте `$retryable = false` для заведомо фатальных сетевых сбоев). Не ловите ошибку и не превращайте её в `Response` внутри `doExecute()` — бросайте, пусть цикл повторов `BaseProvider` отработает.

## Скелет: AnthropicProvider

Набросок, не полная реализация. Anthropic использует другой envelope: только `role: user/assistant` (нет роли `tool`); результаты тулз лежат внутри сообщений `user` как блоки `tool_result`; вызовы тулз ассистента — это content-блоки типа `tool_use`.

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

## Регистрация провайдера

Форма та же, что у встроенных провайдеров: `Client` инстанцирует класс из массива конфига (см. [`Client::createProvider()`](../../src/Client.php)):

```php
$client = new Client($logger);
$client->providers = [
    ['class' => App\Llm\Provider\AnthropicProvider::class, 'token' => getenv('ANTHROPIC_API_KEY'), 'model' => 'claude-3-5-sonnet-latest', 'priority' => 1],
    ['class' => Hameleon2x\Llm\Provider\OpenAiProvider::class, 'token' => getenv('OPENAI_API_KEY'),    'model' => 'gpt-4o-mini',              'priority' => 2],
];
```

Фабрика учитывает: `class`, `token`, `model`, `baseUrl`, `temperature`, `topP`, `maxTokens`, `retryAttempts`, `timeout`, `priority`, `supportedModels`. PSR-3 логгер из `Client` подставляется автоматически. Если вашему провайдеру нужны дополнительные аргументы конструктора — собирайте экземпляр сами и вызывайте `$client->addProvider($instance)`.

## См. также

- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — приоритет и семантика fallback.
- [docs/10-error-handling.md](10-error-handling.md) — во что превращается каждое исключение.
- [docs/11-custom-http-client.md](11-custom-http-client.md) — заменить только транспорт, не формат API.
- [docs/architecture.md](architecture.md) — где находится слой провайдеров.
