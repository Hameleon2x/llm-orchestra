<?php

namespace Hameleon2x\Llm\Provider;

use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Dto\ToolCall;
use Hameleon2x\Llm\Dto\Usage;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;
use Hameleon2x\Llm\Error\ErrorMapper;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Factory\MessageFactory;
use Hameleon2x\Llm\Factory\ToolDefinitionFactory;
use Hameleon2x\Llm\Support\ArrayPath;

/**
 * Провайдер OpenAI-совместимого Chat Completions API. База для шлюзов (OpenRouter, Requesty)
 * и для собственных провайдеров: у них отличаются только базовый URL, имя и карта capture.
 */
class OpenAiProvider extends BaseProvider
{
    /**
     * Поля payload, которые формирует сам провайдер. Расширения из extraParams их не перекрывают:
     * иначе конфиг мог бы незаметно подменить модель или сообщения.
     */
    private const RESERVED_PAYLOAD_KEYS = [
        'model', 'messages', 'tools', 'tool_choice', 'temperature', 'top_p', 'max_tokens', 'seed', 'stream',
    ];

    public function name(): string
    {
        return 'OpenAI';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://api.openai.com';
    }

    /**
     * Поля, которые шлюзы кладут рядом с ответом. Размышления reasoning-моделей приезжают под
     * двумя разными именами — берём то, что пришло.
     */
    protected function defaultCapture(): array
    {
        return [
            'reasoning'         => ['choices.0.message.reasoning_content', 'choices.0.message.reasoning'],
            'annotations'       => 'choices.0.message.annotations',
            'refusal'           => 'choices.0.message.refusal',
            'citations'         => 'citations',
            'systemFingerprint' => 'system_fingerprint',
            'upstream'          => 'provider',
        ];
    }

    public function execute(ResolvedCall $call): Response
    {
        $payload = $this->buildPayload($call);

        $startedAt = microtime(true);
        $body = $this->client()->chat($payload, $call->headers, $call->timeout);
        $latency = microtime(true) - $startedAt;

        $raw = json_decode($body, true);
        if (!is_array($raw)) {
            throw new LlmException(new ErrorInfo(
                ErrorCategory::INVALID_RESPONSE,
                'Ответ провайдера не разбирается как JSON: ' . json_last_error_msg()
            ));
        }

        // Часть шлюзов отдаёт ошибку с кодом 200 и полем error в теле.
        $payloadError = ErrorMapper::fromPayload($raw);
        if ($payloadError !== null) {
            throw new LlmException($payloadError);
        }

        return $this->parse($raw, $call, $latency);
    }

    /**
     * Тело запроса: расширения снизу, поля провайдера сверху.
     */
    protected function buildPayload(ResolvedCall $call): array
    {
        $payload = $call->extraParams;
        foreach (self::RESERVED_PAYLOAD_KEYS as $key) {
            unset($payload[$key]);
        }

        $payload['model'] = $call->modelName();
        $payload['messages'] = array_map(
            static fn($message) => MessageFactory::toArray($message),
            $call->request->messages
        );

        $payload += $call->paramsPayload();

        if (!empty($call->request->tools)) {
            $payload['tools'] = array_map(
                static fn($tool) => ToolDefinitionFactory::toArray($tool),
                $call->request->tools
            );
            if ($call->request->toolChoice !== null) {
                $payload['tool_choice'] = $call->request->toolChoice;
            }
        }

        return $payload;
    }

    /**
     * Разбор успешного ответа.
     *
     * @throws LlmException если ответ пуст или вызовы инструментов пришли оборванными
     */
    protected function parse(array $raw, ResolvedCall $call, float $latency): Response
    {
        $choice = ArrayPath::get($raw, 'choices.0');
        if (!is_array($choice) || !isset($choice['message'])) {
            throw new LlmException(new ErrorInfo(
                ErrorCategory::INVALID_RESPONSE,
                'В ответе провайдера нет ни одного варианта ответа модели.'
            ));
        }

        $message = (array)$choice['message'];
        $toolCalls = $this->parseToolCalls($message);
        $content = $this->normalizeContent($message['content'] ?? null);
        $finishReason = isset($choice['finish_reason']) ? (string)$choice['finish_reason'] : null;

        // Оборванный по лимиту токенов вызов инструмента опаснее пустого ответа: аргументы
        // не разбираются, и инструмент отработал бы на неполных данных.
        foreach ($toolCalls as $toolCall) {
            if ($toolCall->hasBrokenArguments()) {
                throw new LlmException(new ErrorInfo(
                    ErrorCategory::INVALID_RESPONSE,
                    'Аргументы вызова инструмента «' . $toolCall->getFunctionName() . '» пришли оборванными.'
                ));
            }
        }

        if (trim((string)$content) === '' && $toolCalls === []) {
            throw new LlmException(new ErrorInfo(
                ErrorCategory::EMPTY_RESPONSE,
                'Модель вернула ход без текста и без вызовов инструментов'
                . ($finishReason !== null ? " (finish_reason: {$finishReason})" : '') . '.'
            ));
        }

        $response = new Response();
        $response->content = $content;
        $response->toolCalls = $toolCalls;
        $response->usage = $this->parseUsage($raw);
        $response->modelKey = $call->modelKey();
        $response->modelName = isset($raw['model']) ? (string)$raw['model'] : $call->modelName();
        $response->providerKey = $call->providerKey();
        $response->extra = $this->capture($raw, $call);
        $response->metadata = [
            'finishReason' => $finishReason,
            'latency'      => $latency,
        ];
        $response->setRaw($call->keepRaw ? $raw : null);

        return $response;
    }

    /**
     * Потребление токенов. Пути фиксированы: это стандарт OpenAI-совместимых API, а всё
     * нестандартное забирается картой capture.
     */
    protected function parseUsage(array $raw): Usage
    {
        $usage = new Usage();
        $usage->calls = 1;
        $usage->promptTokens = (int)ArrayPath::get($raw, 'usage.prompt_tokens', 0);
        $usage->completionTokens = (int)ArrayPath::get($raw, 'usage.completion_tokens', 0);
        $usage->totalTokens = (int)ArrayPath::get(
            $raw,
            'usage.total_tokens',
            $usage->promptTokens + $usage->completionTokens
        );
        $usage->cachedTokens = (int)ArrayPath::get($raw, 'usage.prompt_tokens_details.cached_tokens', 0);
        $usage->reasoningTokens = (int)ArrayPath::get($raw, 'usage.completion_tokens_details.reasoning_tokens', 0);

        $cost = ArrayPath::get($raw, 'usage.cost');
        if (is_numeric($cost)) {
            $usage->cost = (float)$cost;
        }

        return $usage;
    }

    /**
     * @return ToolCall[]
     */
    protected function parseToolCalls(array $message): array
    {
        if (!isset($message['tool_calls']) || !is_array($message['tool_calls'])) {
            return [];
        }

        $toolCalls = [];
        foreach ($message['tool_calls'] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $function = (array)($raw['function'] ?? []);
            $toolCalls[] = new ToolCall(
                (string)($raw['id'] ?? ''),
                (string)($raw['type'] ?? 'function'),
                [
                    'name'      => (string)($function['name'] ?? ''),
                    'arguments' => $function['arguments'] ?? '{}',
                ],
                $raw
            );
        }

        return $toolCalls;
    }

    /**
     * Текст ответа. Обычно строка, но часть провайдеров отдаёт список блоков — склеиваем текстовые.
     *
     * @param mixed $content
     */
    protected function normalizeContent($content): ?string
    {
        if ($content === null || is_string($content)) {
            return $content;
        }

        if (!is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_string($block)) {
                $parts[] = $block;
                continue;
            }
            if (is_array($block) && isset($block['text']) && is_string($block['text'])) {
                $parts[] = $block['text'];
            }
        }

        return $parts === [] ? null : implode('', $parts);
    }
}
