<?php

namespace Hameleon2x\Llm\Exception;

use Hameleon2x\Llm\Error\ErrorInfo;
use RuntimeException;
use Throwable;

/**
 * Сбой LLM-вызова. Всё, что нужно знать об ошибке, лежит в ErrorInfo — отдельных классов на
 * каждую разновидность сбоя нет: разновидность это категория, а не тип исключения.
 *
 * Исключение живёт только внутри провайдера: наружу Orchestra и Runner отдают Response/Result
 * с полем error, ничего не бросая.
 */
class LlmException extends RuntimeException
{
    private ErrorInfo $info;

    public function __construct(ErrorInfo $info, ?Throwable $previous = null)
    {
        parent::__construct($info->message, $info->httpStatus ?? 0, $previous ?? $info->exception);
        $this->info = $info;
    }

    public function info(): ErrorInfo
    {
        return $this->info;
    }

    public function category(): string
    {
        return $this->info->category;
    }

    /**
     * Короткий способ бросить сбой известной категории из своего провайдера.
     */
    public static function of(string $category, string $message, ?Throwable $previous = null): self
    {
        return new self(new ErrorInfo($category, $message), $previous);
    }
}
