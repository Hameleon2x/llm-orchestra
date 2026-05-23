<?php

namespace Hameleon2x\Llm\Tool;

use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

/**
 * Контракт тулзы LLM-агента: имя, описание, параметры, исполнение, признак отображения в UI.
 */
interface ToolInterface
{
    /** Имя функции в API (напр. get_weather). */
    public function getName(): string;

    /**
     * Описание для модели в списке tools: когда и зачем вызывать тулзу.
     */
    public function getDescription(): string;

    /**
     * Пояснение к выходу тулзы для системного промта; подмешивается только после того, как
     * эта тулза появилась в истории вызовов. Структура JSON-ответа, смысл полей, как
     * интерпретировать. Вход (параметры) сюда не повторять — он задаётся getParameters().
     * Пустая строка — без дополнения.
     */
    public function appendToSystemPromptAfterUse(): string;

    /**
     * @return Property[] свойства для JSON Schema parameters
     */
    public function getParameters(): array;

    /**
     * @param array $args декодированные аргументы от LLM
     */
    public function execute(array $args): Result;

    /**
     * Нужно ли рендерить вызов тулзы в UI чата (виджеты, превью результата).
     */
    public function shouldDisplay(array $args): bool;
}
