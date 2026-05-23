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
     * @param Message[] $messages
     */
    public function __construct(
        bool $success,
        ?string $content,
        ?string $error,
        array $messages,
        int $turnsUsed,
        int $toolCallsUsed,
        ?Usage $usage = null
    ) {
        $this->success = $success;
        $this->content = $content;
        $this->error = $error;
        $this->messages = $messages;
        $this->turnsUsed = $turnsUsed;
        $this->toolCallsUsed = $toolCallsUsed;
        $this->usage = $usage ?? new Usage();
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
}
