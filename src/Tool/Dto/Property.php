<?php

namespace Hameleon2x\Llm\Tool\Dto;

/**
 * Описание одного параметра тулзы. Из списка таких DTO SchemaBuilder собирает
 * JSON Schema parameters.
 */
final class Property
{
    public string $name;

    /** @var string|array 'integer', 'string', 'array' или union ['integer','null'] */
    public $type;

    public ?string $description = null;
    public bool    $required    = false;

    /** Для type='array': схема элементов, напр. ['type' => 'integer'] */
    public ?array $items = null;

    public function __construct(
        string  $name,
                $type,
        ?string $description = null,
        bool    $required = false,
        ?array  $items = null
    )
    {
        $this->name = $name;
        $this->type = $type;
        $this->description = $description;
        $this->required = $required;
        $this->items = $items;
    }

    public function toSchemaProperty(): array
    {
        $p = ['type' => $this->type];
        if ($this->description !== null && $this->description !== '') {
            $p['description'] = $this->description;
        }
        if ($this->items !== null) {
            $p['items'] = $this->items;
        }
        return $p;
    }
}
