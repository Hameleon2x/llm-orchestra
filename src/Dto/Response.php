<?php

namespace Hameleon2x\Llm\Dto;

use Hameleon2x\Llm\Error\ErrorInfo;
use Hameleon2x\Llm\Support\ArrayPath;

/**
 * Ответ модели.
 *
 * Три слоя данных, чтобы библиотека не переписывалась каждый раз, когда провайдер добавляет поле:
 *   - типизированное (content, toolCalls, usage) — на этом работает движок;
 *   - extra — данные провайдера, приведённые к нашим именам картой capture;
 *   - raw — ответ целиком, как пришёл.
 *
 * Ошибки: успех — это error === null. Категория сбоя лежит в ErrorInfo, разбирать сообщения
 * провайдеров строками не нужно.
 */
final class Response
{
    /** Текст ответа. null, если модель вернула только вызовы инструментов или произошёл сбой. */
    public ?string $content = null;

    /** @var ToolCall[] */
    public array $toolCalls = [];

    public Usage $usage;

    /** Ключ модели каталога, которая фактически ответила (может отличаться от запрошенной). */
    public string $modelKey = '';

    /** Слаг модели, который ушёл в API. */
    public string $modelName = '';

    /** Ключ провайдера каталога. */
    public string $providerKey = '';

    /** Служебное: finishReason, latency, attempts. */
    public array $metadata = [];

    /** Данные провайдера по карте capture: reasoning, annotations, refusal и т. п. */
    public array $extra = [];

    /** Сбой вызова; null при успехе. */
    public ?ErrorInfo $error = null;

    /** @var AttemptLog[] журнал попыток, включая переключения на другие модели */
    public array $attempts = [];

    /** Сырой ответ провайдера; null, если провайдер настроен его не хранить. */
    private ?array $rawData = null;

    public function __construct()
    {
        $this->usage = new Usage();
    }

    /**
     * Неуспешный ответ по разобранной ошибке.
     */
    public static function failed(ErrorInfo $error): self
    {
        $response = new self();
        $response->error = $error;
        $response->modelKey = $error->modelKey;
        $response->providerKey = $error->providerKey;

        return $response;
    }

    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    /**
     * Модель не сказала ничего: ни текста, ни вызовов инструментов.
     */
    public function isEmpty(): bool
    {
        return trim((string)$this->content) === '' && $this->toolCalls === [];
    }

    /**
     * Данные провайдера по имени из карты capture.
     *
     * @return mixed
     */
    public function extra(string $key, $default = null)
    {
        return $this->extra[$key] ?? $default;
    }

    /**
     * Сырой ответ целиком или его часть по пути `choices.0.message.reasoning_content`.
     *
     * @return mixed
     */
    public function raw(?string $path = null)
    {
        if ($this->rawData === null) {
            return null;
        }
        if ($path === null) {
            return $this->rawData;
        }

        return ArrayPath::get($this->rawData, $path);
    }

    public function setRaw(?array $raw): void
    {
        $this->rawData = $raw;
    }

    /**
     * Причина завершения генерации от провайдера: stop, length, tool_calls, content_filter.
     */
    public function finishReason(): ?string
    {
        return $this->metadata['finishReason'] ?? null;
    }

    /**
     * Ответ оборван по лимиту токенов. Для текста это потеря хвоста, для вызова инструмента —
     * почти всегда битые аргументы.
     */
    public function isTruncated(): bool
    {
        return $this->finishReason() === 'length';
    }

    /** Длительность вызова, секунды (без пауз между попытками). */
    public function latency(): float
    {
        return (float)($this->metadata['latency'] ?? 0.0);
    }
}
