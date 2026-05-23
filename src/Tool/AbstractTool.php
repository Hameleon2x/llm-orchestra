<?php

namespace Hameleon2x\Llm\Tool;

/**
 * База для тулзы: дефолтный shouldDisplay() = false. Остальные методы интерфейса
 * наследник обязан реализовать сам.
 */
abstract class AbstractTool implements ToolInterface
{
    public function shouldDisplay(array $args): bool
    {
        return false;
    }
}
