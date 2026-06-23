<?php

namespace Hameleon2x\Llm\Agent\Dto;

use Hameleon2x\Llm\Dto\Message;

/**
 * Результат прогона агентского цикла Runner.
 */
class Result
{
    public bool $success;
    public ?string $content;

    /** Текст ошибки, когда success = false */
    public ?string $error;

    /** @var Message[] Полная история диалога после прогона (без system-сообщения) */
    public array $messages;

    public int $turnsUsed;
    public int $toolCallsUsed;

    /** Сводная статистика LLM-вызовов за прогон */
    public Usage $usage;

    /**
     * Прогон приостановлен: модель вызвала suspend-тулзу, её результат ждёт внешнего ввода
     * (human-in-the-loop). success=false, content/error=null.
     */
    public bool $suspended = false;

    /** @var string[] id вызовов, чьи результаты нужно предоставить для возобновления (когда suspended=true). */
    public array $pendingToolCallIds = [];

    /**
     * @param Message[] $messages
     */
    public function __construct(
        bool $success,
        ?string $content,
        ?string $error,
        array $messages,
        int $turnsUsed,
        int $toolCallsUsed,
        ?Usage $usage = null,
        bool $suspended = false,
        array $pendingToolCallIds = []
    ) {
        $this->success = $success;
        $this->content = $content;
        $this->error = $error;
        $this->messages = $messages;
        $this->turnsUsed = $turnsUsed;
        $this->toolCallsUsed = $toolCallsUsed;
        $this->usage = $usage ?? new Usage();
        $this->suspended = $suspended;
        $this->pendingToolCallIds = $pendingToolCallIds;
    }

    /**
     * @param Message[] $messages
     */
    public static function success(
        string $content,
        array $messages,
        int $turnsUsed,
        int $toolCallsUsed,
        ?Usage $usage = null
    ): self {
        return new self(true, $content, null, $messages, $turnsUsed, $toolCallsUsed, $usage);
    }

    /**
     * @param Message[] $messages
     */
    public static function error(
        string $error,
        array $messages,
        int $turnsUsed,
        int $toolCallsUsed,
        ?Usage $usage = null
    ): self {
        return new self(false, null, $error, $messages, $turnsUsed, $toolCallsUsed, $usage);
    }

    /**
     * Прогон приостановлен suspend-тулзами: результаты вызовов $pendingToolCallIds будут предоставлены
     * извне, после чего прогон возобновляется с подставленными tool-сообщениями. Возобновлять можно
     * только когда закрыты ВСЕ вызовы хода (правило протокола: каждый tool_call требует tool-ответа).
     *
     * @param string[]  $pendingToolCallIds
     * @param Message[] $messages
     */
    public static function suspended(
        array $pendingToolCallIds,
        array $messages,
        int $turnsUsed,
        int $toolCallsUsed,
        ?Usage $usage = null
    ): self {
        return new self(false, null, null, $messages, $turnsUsed, $toolCallsUsed, $usage, true, $pendingToolCallIds);
    }
}
