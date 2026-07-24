<?php

namespace Hameleon2x\Llm\Exception;

use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;

/**
 * Ошибка каталога: несуществующий провайдер у модели, неизвестный ключ в цепочке фолбэка,
 * дублирующийся алиас, отсутствующий класс провайдера.
 *
 * Бросается при сборке Registry и при обращении к неизвестной модели — то есть в момент, когда
 * ошибку ещё можно исправить в конфиге, а не посреди прогона.
 */
class LlmConfigException extends LlmException
{
    public function __construct(string $message)
    {
        parent::__construct(new ErrorInfo(ErrorCategory::CONFIG, $message, false));
    }
}
