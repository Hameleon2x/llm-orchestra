<?php

namespace Hameleon2x\Llm\Dto;

use Hameleon2x\Llm\Enum\Status;
use Throwable;

/**
 * Ответ от LLM.
 */
class Response
{
    /** Статус из Status::* */
    public string $status;

    /** Класс провайдера, который вернул ответ */
    public string $provider;

    public string $model;
    public ?string $content;

    /** @var ToolCall[] */
    public array $toolCalls;

    /** Метаданные: токены, latency, attempts и т.д. */
    public array $metadata;

    public ?string $error;
    public ?Throwable $exception;

    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        string     $status,
        string     $provider,
        string     $model,
        ?string    $content = null,
        array      $toolCalls = [],
        array      $metadata = [],
        ?string    $error = null,
        ?Throwable $exception = null
    )
    {
        $this->status = $status;
        $this->provider = $provider;
        $this->model = $model;
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->metadata = $metadata;
        $this->error = $error;
        $this->exception = $exception;
    }

    public function isSuccess(): bool
    {
        return $this->status === Status::SUCCESS;
    }

    public function hasToolCalls(): bool
    {
        return !empty($this->toolCalls);
    }

    public function getTotalTokens(): int
    {
        return $this->metadata['totalTokens'] ?? 0;
    }

    public function getPromptTokens(): int
    {
        return $this->metadata['promptTokens'] ?? 0;
    }

    public function getCompletionTokens(): int
    {
        return $this->metadata['completionTokens'] ?? 0;
    }

    /** Время выполнения запроса в секундах */
    public function getLatency(): float
    {
        return $this->metadata['latency'] ?? 0.0;
    }

    public static function success(
        string  $provider,
        string  $model,
        ?string $content = null,
        array   $toolCalls = [],
        array   $metadata = []
    ): self
    {
        return new self(
            Status::SUCCESS,
            $provider,
            $model,
            $content,
            $toolCalls,
            $metadata
        );
    }

    public static function error(
        string     $status,
        string     $provider,
        string     $model,
        string     $error,
        ?Throwable $exception = null,
        array      $metadata = []
    ): self
    {
        return new self(
            $status,
            $provider,
            $model,
            null,
            [],
            $metadata,
            $error,
            $exception
        );
    }
}
