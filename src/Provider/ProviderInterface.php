<?php

namespace Hameleon2x\Llm\Provider;

use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Exception\LlmException;

/**
 * Транспорт до конкретного API: собрать payload, отправить, разобрать ответ.
 *
 * Провайдер намеренно ничего не решает: повторы, переключение моделей и слияние настроек делает
 * Orchestra, а сюда приходит готовый ResolvedCall. Поэтому свой провайдер — это один метод.
 *
 * Исполнитель создаёт провайдера как `new $class($definition, $logger)` — конструктор с такой
 * сигнатурой обязателен (см. BaseProvider).
 */
interface ProviderInterface
{
    /**
     * Выполнить вызов.
     *
     * @throws LlmException при любом сбое — с категорией в ErrorInfo
     */
    public function execute(ResolvedCall $call): Response;
}
