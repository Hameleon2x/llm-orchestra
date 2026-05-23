<?php

namespace Hameleon2x\Llm\Tool;

use Hameleon2x\Llm\Tool\Dto\Property;

/**
 * Сборщик JSON Schema параметров тулзы из списка Property.
 *
 * Опционально инъектит в схему обязательный служебный параметр log_message —
 * короткое пояснение вызова, которое модель пишет для истории диалога. Описание
 * параметра задаёт вызывающий код (у разных тулбоксов формулировка разная).
 *
 * Используется AbstractToolbox (для агентского цикла) и внешними MCP-реестрами.
 */
final class SchemaBuilder
{
    /** Имя служебного параметра-пояснения вызова тулзы. */
    public const LOG_MESSAGE_PARAM = 'log_message';

    /** Дефолтное описание log_message, если потомок не задал своё. */
    public const LOG_MESSAGE_DESCRIPTION_DEFAULT = 'Краткое пояснение: что делаешь этим вызовом и зачем.';

    /**
     * @param Property[]  $properties
     * @return array{type: string, properties: array|object, required: string[]}
     */
    public static function build(
        array   $properties,
        bool    $withLogMessage = false,
        ?string $logMessageDescription = null
    ): array
    {
        $schemaProperties = [];
        $required = [];
        foreach ($properties as $p) {
            $schemaProperties[$p->name] = $p->toSchemaProperty();
            if ($p->required) {
                $required[] = $p->name;
            }
        }

        if ($withLogMessage) {
            $schemaProperties[self::LOG_MESSAGE_PARAM] = [
                'type'        => 'string',
                'description' => $logMessageDescription ?? self::LOG_MESSAGE_DESCRIPTION_DEFAULT,
            ];
            $required[] = self::LOG_MESSAGE_PARAM;
        }

        return [
            'type'       => 'object',
            // properties должно быть JSON-объектом даже когда параметров нет (MCP-кейс).
            'properties' => $schemaProperties === [] ? (object)[] : $schemaProperties,
            'required'   => $required,
        ];
    }
}
