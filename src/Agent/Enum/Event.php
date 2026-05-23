<?php

namespace Hameleon2x\Llm\Agent\Enum;

/**
 * Типы событий агентского цикла, передаваемые в emit-колбэк Runner.
 */
class Event
{
    /**
     * Ответ ассистента с вызовами тулз.
     * content — текст ассистента; meta['tool_calls'] — массив вызовов (ToolCallFactory::toArray()).
     */
    public const ASSISTANT_MESSAGE = 'assistant_message';

    /**
     * Вызов тулзы.
     * content — имя тулзы; meta: tool_call_id, tool (имя), args (аргументы).
     */
    public const TOOL_CALL = 'tool_call';

    /**
     * Результат тулзы.
     * content — JSON результата; meta: tool_call_id, tool (имя), ok (bool — успех/ошибка тулзы).
     */
    public const TOOL_RESULT = 'tool_result';
}
