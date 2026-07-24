<?php

namespace Hameleon2x\Llm\Agent\Dto;

use Hameleon2x\Llm\Agent\Enum\Finish;
use Hameleon2x\Llm\Dto\AttemptLog;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Dto\Usage;
use Hameleon2x\Llm\Error\ErrorInfo;

/**
 * Результат прогона агентского цикла.
 *
 * Причина остановки лежит в $finish (Finish::*), сбой — в $error с категорией. Разбирать текст
 * ответа, чтобы понять, чем кончился прогон, не нужно.
 */
final class Result
{
    public bool $success;

    public ?string $content;

    /** Сбой прогона; null, если прогон завершился без ошибки. */
    public ?ErrorInfo $error;

    /** Причина остановки: Finish::*. */
    public string $finish;

    /** @var Message[] полная история после прогона (без системного сообщения) */
    public array $messages;

    public int $turnsUsed;

    public int $toolCallsUsed;

    public Usage $usage;

    /** Ключ модели, которая работала последней (после фолбэка отличается от запрошенной). */
    public string $modelKey = '';

    /** @var AttemptLog[] журнал попыток за весь прогон */
    public array $attempts = [];

    /** Последний ответ модели: сырой ответ провайдера, extra, finishReason. */
    public ?Response $lastResponse = null;

    /**
     * Прогон приостановлен: инструмент ждёт внешнего ввода (human-in-the-loop).
     */
    public bool $suspended = false;

    /**
     * @var string[] id вызовов, чьи результаты нужно предоставить для возобновления
     */
    public array $pendingToolCallIds = [];

    /**
     * @param Message[] $messages
     */
    private function __construct(
        bool       $success,
        ?string    $content,
        ?ErrorInfo $error,
        string     $finish,
        array      $messages,
        int        $turnsUsed,
        int        $toolCallsUsed,
        ?Usage     $usage
    ) {
        $this->success = $success;
        $this->content = $content;
        $this->error = $error;
        $this->finish = $finish;
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
        array  $messages,
        int    $turnsUsed,
        int    $toolCallsUsed,
        ?Usage $usage = null,
        string $finish = Finish::COMPLETED
    ): self {
        return new self(true, $content, null, $finish, $messages, $turnsUsed, $toolCallsUsed, $usage);
    }

    /**
     * @param Message[] $messages
     */
    public static function error(
        ErrorInfo $error,
        array     $messages,
        int       $turnsUsed,
        int       $toolCallsUsed,
        ?Usage    $usage = null,
        string    $finish = Finish::ERROR
    ): self {
        return new self(false, null, $error, $finish, $messages, $turnsUsed, $toolCallsUsed, $usage);
    }

    /**
     * Прогон приостановлен: результаты вызовов $pendingToolCallIds предоставит внешний код, после
     * чего прогон возобновляется. Возобновлять можно, только когда закрыты ВСЕ вызовы хода —
     * протокол требует ответа на каждый tool_call.
     *
     * @param string[]  $pendingToolCallIds
     * @param Message[] $messages
     */
    public static function suspended(
        array  $pendingToolCallIds,
        array  $messages,
        int    $turnsUsed,
        int    $toolCallsUsed,
        ?Usage $usage = null
    ): self {
        $result = new self(false, null, null, Finish::SUSPENDED, $messages, $turnsUsed, $toolCallsUsed, $usage);
        $result->suspended = true;
        $result->pendingToolCallIds = $pendingToolCallIds;

        return $result;
    }
}
