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
     * Дополнение к системному промту по уже вызванной тулзе: текст, который Runner
     * добавит в промт на следующих оборотах цикла. Пустая строка — без дополнения.
     */
    public function systemPromptAddition(string $name): string;
}
