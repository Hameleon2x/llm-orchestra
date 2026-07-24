<?php

namespace Hameleon2x\Llm\Error;

use Throwable;

/**
 * Разобранная ошибка LLM-вызова: категория, контекст (какая модель у какого провайдера) и сырой
 * ответ провайдера. Приложение принимает решения по `category`, а `message`/`raw` показывает
 * или логирует.
 */
final class ErrorInfo
{
    /** Категория из ErrorCategory::*. */
    public string $category;

    /** Техническое сообщение: текст провайдера или описание сбоя. Для UI не предназначено. */
    public string $message;

    /** Повторять ли запрос. Значение по умолчанию берётся из категории. */
    public bool $retryable;

    /** HTTP-код ответа, если сбой произошёл на уровне HTTP. */
    public ?int $httpStatus = null;

    /** Машинный код провайдера, если он его прислал (`context_length_exceeded` и т. п.). */
    public ?string $providerCode = null;

    /** Ключ провайдера из каталога, на котором произошёл сбой. */
    public string $providerKey = '';

    /** Ключ модели из каталога, на которой произошёл сбой. */
    public string $modelKey = '';

    /** Исходное исключение, если сбой пришёл через него. */
    public ?Throwable $exception = null;

    /** Тело ответа провайдера как есть — чтобы не разбирать причину по строке сообщения. */
    public array $raw = [];

    public function __construct(string $category, string $message, ?bool $retryable = null)
    {
        $this->category = $category;
        $this->message = $message;
        $this->retryable = $retryable ?? ErrorCategory::isRetryableByDefault($category);
    }

    /**
     * Ошибка одной из перечисленных категорий.
     */
    public function is(string ...$categories): bool
    {
        return in_array($this->category, $categories, true);
    }

    /**
     * Симптом обрыва связи с сервером ИИ (сеть, таймаут, пустой ход модели).
     */
    public function isConnectionDrop(): bool
    {
        return ErrorCategory::isConnectionDrop($this->category);
    }

    /**
     * Копия с проставленным контекстом вызова. Провайдер знает про сбой всё, кроме того, под какими
     * ключами каталога он выполнялся, — контекст дописывает исполнитель.
     */
    public function withContext(string $providerKey, string $modelKey): self
    {
        $clone = clone $this;
        $clone->providerKey = $providerKey;
        $clone->modelKey = $modelKey;

        return $clone;
    }

    /**
     * Компактное представление для логов: без сырого тела и без исключения.
     */
    public function toArray(): array
    {
        return [
            'category'     => $this->category,
            'message'      => $this->message,
            'retryable'    => $this->retryable,
            'httpStatus'   => $this->httpStatus,
            'providerCode' => $this->providerCode,
            'provider'     => $this->providerKey,
            'model'        => $this->modelKey,
        ];
    }
}
