<?php

namespace Hameleon2x\Llm\Exception;

use Throwable;

/**
 * Сетевые ошибки, таймауты, 5xx от провайдера. Retryable.
 */
class LlmProviderException extends LlmException
{
    public function __construct(string $message = "Provider error", int $code = 0, ?Throwable $previous = null, bool $retryable = true)
    {
        parent::__construct($message, $code, $previous, $retryable);
    }
}
