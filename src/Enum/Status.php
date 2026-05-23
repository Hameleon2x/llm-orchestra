<?php

namespace Hameleon2x\Llm\Enum;

/**
 * Статусы ответа от LLM.
 */
class Status
{
    public const SUCCESS = 'success';

    /** Ошибка провайдера (retryable) */
    public const PROVIDER_ERROR = 'provider_error';

    /** Превышен лимит запросов (429, retryable) */
    public const RATE_LIMIT = 'rate_limit';

    /** Ошибка валидации запроса (not retryable) */
    public const VALIDATION_ERROR = 'validation_error';

    public const TIMEOUT = 'timeout';
    public const ERROR = 'error';
}
