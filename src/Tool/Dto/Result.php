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

    private function __construct(bool $ok, array $data, ?string $error)
    {
        $this->ok = $ok;
        $this->data = $data;
        $this->error = $error;
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
