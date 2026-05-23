<?php

namespace Hameleon2x\Llm\Exception;

use Exception;
use Throwable;

/**
 * Базовое исключение LLM-компонента. Флаг $retryable сообщает Client/BaseProvider,
 * стоит ли повторять запрос.
 */
class LlmException extends Exception
{
    public bool $retryable = false;

    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, bool $retryable = false)
    {
        parent::__construct($message, $code, $previous);
        $this->retryable = $retryable;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
