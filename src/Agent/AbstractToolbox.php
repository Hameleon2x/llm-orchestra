<?php

namespace Hameleon2x\Llm\Agent;

use Hameleon2x\Llm\Dto\ToolDefinition;
use Hameleon2x\Llm\Tool\Dto\Result;
use Hameleon2x\Llm\Tool\SchemaBuilder;
use Hameleon2x\Llm\Tool\ToolInterface;

/**
 * Базовая реализация ToolboxInterface поверх массива ToolInterface[].
 *
 * Потомок задаёт список тулз через buildTools() (может пробрасывать в их конструкторы
 * свои сервисы); при необходимости включает инъекцию служебного параметра log_message
 * через $withLogMessage / $logMessageDescription.
 *
 * Сборка JSON Schema параметров делегируется SchemaBuilder.
 */
abstract class AbstractToolbox implements ToolboxInterface
{
    /** Добавлять ли в схему каждой тулзы обязательный log_message. */
    protected bool $withLogMessage = false;

    /** Описание log_message в схеме; null — дефолт SchemaBuilder. */
    protected ?string $logMessageDescription = null;

    /** @var ToolInterface[]|null */
    private ?array $tools = null;

    /**
     * Список инстансов тулз. Вызывается лениво один раз. Здесь потомок может пробросить
     * сервисы в конструкторы тулз.
     *
     * @return ToolInterface[]
     */
    abstract protected function buildTools(): array;

    /**
     * @return ToolDefinition[]
     */
    public function definitions(): array
    {
        $result = [];
        foreach ($this->getTools() as $tool) {
            $result[] = ToolDefinition::function(
                $tool->getName(),
                $tool->getDescription(),
                SchemaBuilder::build(
                    $tool->getParameters(),
                    $this->withLogMessage,
                    $this->logMessageDescription
                )
            );
        }
        return $result;
    }

    public function execute(string $name, array $args): Result
    {
        $tool = $this->findByName($name);
        if ($tool === null) {
            return Result::error('Unknown tool: ' . $name);
        }
        return $tool->execute($args);
    }

    public function systemPromptAddition(string $name): string
    {
        $tool = $this->findByName($name);
        return $tool !== null ? $tool->getSystemPromptDescription() : '';
    }

    public function findByName(string $name): ?ToolInterface
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->getName() === $name) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * Инстансы тулз тулбокса (ленивое построение).
     *
     * @return ToolInterface[]
     */
    protected function getTools(): array
    {
        if ($this->tools === null) {
            $this->tools = $this->buildTools();
        }
        return $this->tools;
    }
}
