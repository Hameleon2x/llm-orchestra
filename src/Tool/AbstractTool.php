<?php

namespace Hameleon2x\Llm\Tool;

/**
 * База для тулзы: дефолтные firstUseHint() = '' (без пояснения) и имя ключа пояснения.
 * getName/getDescription/getParameters/execute наследник реализует сам.
 *
 * shouldDisplay() — признак для интерфейса приложения: рисовать ли вызов в чате. Движку он не
 * нужен и в ToolInterface не входит, поэтому свою тулзу можно объявить и без него.
 */
abstract class AbstractTool implements ToolInterface
{
    /** Ключ по умолчанию, под которым firstUseHint() подмешивается в результат первого вызова. */
    public const DEFAULT_FIRST_USE_HINT_KEY = 'hint_use';

    public function shouldDisplay(array $args): bool
    {
        return false;
    }

    public function firstUseHint(): string
    {
        return '';
    }

    public function firstUseHintKey(): string
    {
        return self::DEFAULT_FIRST_USE_HINT_KEY;
    }
}
