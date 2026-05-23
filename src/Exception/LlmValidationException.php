<?php

namespace Hameleon2x\Llm\Exception;

use Throwable;

/**
 * Ошибка валидации запроса (HTTP 4xx, кроме 429). Not retryable — данные неверные.
 */
class LlmValidationException extends LlmException
{
    public function __construct(string $message = "Validation error", int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous, false);
    }
}
