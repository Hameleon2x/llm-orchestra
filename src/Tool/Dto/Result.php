<?php

namespace Hameleon2x\Llm\Tool\Dto;

/**
 * Результат выполнения тулзы: либо успех с данными (ok), либо ошибка с текстом (error).
 *
 * На уровне PHP — типизированный контракт; для tool-сообщения LLM сериализуется через
 * toJsonArray(): успех — данные как есть; ошибка — ['error' => '...'] (исторический
 * формат, на котором обучены модели).
 */
final class Result
{
    public bool $ok;

    /** Данные успешного результата (плоский ассоциативный массив или список). */
    public array $data;

    public ?string $error;

    /**
     * Тулза просит приостановить агентский цикл: результата сейчас нет, он будет предоставлен
     * извне (ответ пользователя, внешнее событие) и подставлен как tool-сообщение этого вызова
     * при возобновлении. Human-in-the-loop / elicitation. См. Runner и Agent\Dto\Result::suspended().
     */
    public bool $suspended;

    private function __construct(bool $ok, array $data, ?string $error, bool $suspended = false)
    {
        $this->ok = $ok;
        $this->data = $data;
        $this->error = $error;
        $this->suspended = $suspended;
    }

    public static function ok(array $data = []): self
    {
        return new self(true, $data, null);
    }

    /**
     * Ошибка выполнения тулзы (не найдена запись, некорректные аргументы и т.п.).
     */
    public static function error(string $message): self
    {
        return new self(false, [], $message);
    }

    /**
     * Приостановить агентский цикл: тулза не возвращает результат сейчас. Раннер остановит прогон
     * и вернёт Agent\Dto\Result::suspended() с id этого вызова; внешний код предоставит результат
     * позже (например, ответ пользователя) и подставит его как tool-сообщение при возобновлении.
     *
     * Остальные вызовы того же хода раннер исполняет как обычно, а приостановленные копит:
     * возобновить прогон можно, когда закрыт каждый вызов хода. Инструмент, эффект которого
     * зависит от ожидаемого ответа, в один ход с приостанавливающим ставить нельзя — он отработает
     * раньше, чем ответ появится.
     */
    public static function suspend(): self
    {
        return new self(false, [], null, true);
    }

    public function isSuspended(): bool
    {
        return $this->suspended;
    }

    /**
     * Сериализация для tool-сообщения LLM или structuredContent MCP.
     */
    public function toJsonArray(): array
    {
        if (!$this->ok) {
            return ['error' => $this->error];
        }
        return $this->data;
    }
}
