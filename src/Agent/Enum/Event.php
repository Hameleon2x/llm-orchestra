<?php

namespace Hameleon2x\Llm\Agent\Enum;

/**
 * События агентского цикла, приходящие в emit-колбэк Runner.
 */
final class Event
{
    /**
     * Ход ассистента с вызовами инструментов.
     * content — текст ассистента; meta: tool_calls, extra (данные провайдера, включая reasoning),
     * usage (потребление хода), model (ключ модели, ответившей на этот ход).
     */
    public const ASSISTANT_MESSAGE = 'assistant_message';

    /**
     * Модель запросила вызов инструмента.
     * content — имя инструмента; meta: tool_call_id, tool, args.
     */
    public const TOOL_CALL = 'tool_call';

    /**
     * Результат инструмента.
     * content — JSON результата; meta: tool_call_id, tool, ok (успех инструмента),
     * guard (true, если вызов отклонён проверкой аргументов), exception (true, если инструмент упал).
     */
    public const TOOL_RESULT = 'tool_result';

    /**
     * Попытка вызова модели не удалась.
     * content — категория ошибки; meta: model, provider, attempt, max_attempts (сколько попыток
     * всего разрешает политика — для «повтор 2 из 3»), category, message,
     * will_retry (будет ли повтор), delay (пауза перед повтором).
     */
    public const ATTEMPT_FAILED = 'attempt_failed';

    /**
     * Работа передана следующей модели цепочки фолбэка.
     * content — ключ новой модели; meta: from, to.
     */
    public const MODEL_FALLBACK = 'model_fallback';
}
