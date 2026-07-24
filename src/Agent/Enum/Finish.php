<?php

namespace Hameleon2x\Llm\Agent\Enum;

/**
 * Чем закончился прогон агентского цикла. Отвечает на вопрос «почему цикл остановился» без
 * разбора текста ответа: заглушки об исчерпании лимитов раньше отличались только формулировкой.
 */
final class Finish
{
    /** Модель дала финальный ответ. */
    public const COMPLETED = 'completed';

    /** Ответ получен добивкой после исчерпания лимита вызовов инструментов. */
    public const TOOL_LIMIT = 'tool_limit';

    /** Исчерпан лимит оборотов цикла; в content — заглушка из конфига. */
    public const TURNS_EXHAUSTED = 'turns_exhausted';

    /** Истёк отведённый на прогон срок. */
    public const DEADLINE = 'deadline';

    /** Сбой вызова модели: все повторы и переключения не помогли. */
    public const ERROR = 'error';

    /** Прогон приостановлен: инструмент ждёт внешнего ввода. */
    public const SUSPENDED = 'suspended';
}
