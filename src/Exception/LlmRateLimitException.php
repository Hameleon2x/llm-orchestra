<?php

namespace Hameleon2x\Llm\Exception;

use Throwable;

/**
 * Превышение лимита запросов (HTTP 429). Retryable.
 */
class LlmRateLimitException extends LlmException
{
    public function __construct(string $message = "Rate limit exceeded", int $code = 429, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous, true);
    }
}
