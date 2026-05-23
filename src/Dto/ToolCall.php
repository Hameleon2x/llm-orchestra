<?php

namespace Hameleon2x\Llm\Dto;

/**
 * Вызов инструмента от LLM.
 */
class ToolCall
{
    public string $id;

    /** 'function' */
    public string $type;

    /** ['name' => '...', 'arguments' => '{"key": "value"}'] */
    public array $function;

    public function __construct(string $id, string $type, array $function)
    {
        $this->id = $id;
        $this->type = $type;
        $this->function = $function;
    }

    public function getFunctionName(): string
    {
        return $this->function['name'] ?? '';
    }

    /**
     * Аргументы функции как ассоциативный массив (если в JSON — декодируется).
     */
    public function getArguments(): array
    {
        $args = $this->function['arguments'] ?? '{}';
        if (is_string($args)) {
            $decoded = json_decode($args, true);
            return is_array($decoded) ? $decoded : [];
        }
        return $args;
    }
}
