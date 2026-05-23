<?php

namespace Hameleon2x\Llm\Factory;

use Hameleon2x\Llm\Dto\ToolCall;

/**
 * Преобразование ToolCall ↔ массив (формат вызова инструмента OpenAI-совместимого API).
 */
class ToolCallFactory
{
    public static function toArray(ToolCall $toolCall): array
    {
        return [
            'id'       => $toolCall->id,
            'type'     => $toolCall->type,
            'function' => $toolCall->function,
        ];
    }

    public static function fromArray(array $data): ToolCall
    {
        return new ToolCall(
            (string)($data['id'] ?? ''),
            (string)($data['type'] ?? 'function'),
            isset($data['function']) && is_array($data['function']) ? $data['function'] : []
        );
    }
}
