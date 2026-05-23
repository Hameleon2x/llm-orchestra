<?php

namespace Hameleon2x\Llm\Factory;

use Hameleon2x\Llm\Dto\ToolDefinition;

/**
 * Преобразование ToolDefinition → массив (формат инструмента OpenAI-совместимого API).
 */
class ToolDefinitionFactory
{
    public static function toArray(ToolDefinition $tool): array
    {
        return [
            'type'     => $tool->type,
            'function' => $tool->function,
        ];
    }
}
