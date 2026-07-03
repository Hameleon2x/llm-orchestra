<?php

namespace Hameleon2x\Llm\Agent;

use Hameleon2x\Llm\Dto\ToolDefinition;
use Hameleon2x\Llm\Tool\Dto\Result;

/**
 * Реестр тулз для Runner. Реализуется вызывающим модулем — либо через AbstractToolbox,
 * либо собственной реализацией с нуля.
 */
interface ToolboxInterface
{
    /**
     * @return ToolDefinition[] определения тулз для передачи в LLM
     */
    public function definitions(): array;

    /**
     * Исполнить тулзу по имени. Сериализация результата в JSON для tool-сообщения —
     * задача вызывающего кода (Runner делает это через Result::toJsonArray()).
     */
    public function execute(string $name, array $args): Result;

    /**
     * Пояснение к результату тулзы, которое Runner подмешивает в её ответ при ПЕРВОМ вызове
     * в диалоге (под ключом firstUseHintKey()). Пустая строка — без пояснения.
     */
    public function firstUseHint(string $name): string;

    /**
     * Имя ключа, под которым firstUseHint($name) кладётся в результат первого вызова тулзы.
     */
    public function firstUseHintKey(string $name): string;
}
