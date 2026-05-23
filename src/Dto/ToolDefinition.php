<?php

namespace Hameleon2x\Llm\Dto;

/**
 * Описание инструмента (function) для LLM.
 */
class ToolDefinition
{
    /** 'function' */
    public string $type;

    /** ['name' => '...', 'description' => '...', 'parameters' => [...]] */
    public array $function;

    public function __construct(string $type, array $function)
    {
        $this->type = $type;
        $this->function = $function;
    }

    public static function function (string $name, string $description, array $parameters): self
    {
        return new self('function', [
            'name'        => $name,
            'description' => $description,
            'parameters'  => $parameters,
        ]);
    }
}
