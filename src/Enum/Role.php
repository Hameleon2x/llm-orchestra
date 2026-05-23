<?php

namespace Hameleon2x\Llm\Enum;

/**
 * Роли сообщений в диалоге с LLM.
 */
class Role
{
    public const SYSTEM = 'system';
    public const USER = 'user';
    public const ASSISTANT = 'assistant';

    /** Результат вызова функции */
    public const TOOL = 'tool';
}
