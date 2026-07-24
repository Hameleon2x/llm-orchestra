<?php

namespace Hameleon2x\Llm\Dto;

/**
 * Вызов инструмента, запрошенный моделью.
 */
final class ToolCall
{
    public string $id;

    /** 'function' */
    public string $type;

    /** ['name' => '...', 'arguments' => '{"key": "value"}'] */
    public array $function;

    /** Вызов как пришёл от провайдера: там бывают индексы и служебные блоки. */
    public array $raw = [];

    public function __construct(string $id, string $type, array $function, array $raw = [])
    {
        $this->id = $id;
        $this->type = $type;
        $this->function = $function;
        $this->raw = $raw;
    }

    public function getFunctionName(): string
    {
        return $this->function['name'] ?? '';
    }

    /**
     * Аргументы как массив. Модель присылает их строкой JSON; битую строку считаем пустыми
     * аргументами — решение о том, что с этим делать, принимает вызывающий.
     */
    public function getArguments(): array
    {
        $arguments = $this->function['arguments'] ?? '{}';
        if (is_string($arguments)) {
            $decoded = json_decode($arguments, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($arguments) ? $arguments : [];
    }

    /**
     * Аргументы не разобрались: пришла непустая строка, но это не JSON-объект. Симптом обрыва
     * ответа по лимиту токенов.
     */
    public function hasBrokenArguments(): bool
    {
        $arguments = $this->function['arguments'] ?? '';
        if (!is_string($arguments) || trim($arguments) === '') {
            return false;
        }

        return !is_array(json_decode($arguments, true));
    }
}
