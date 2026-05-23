<?php

namespace Hameleon2x\Llm\Factory;

use Hameleon2x\Llm\Dto\Message;

/**
 * Преобразование Message ↔ массив (формат сообщения OpenAI-совместимого API).
 * Тот же формат используется для передачи истории диалога между фронтом и бэком.
 */
class MessageFactory
{
    public static function toArray(Message $message): array
    {
        $result = ['role' => $message->role];

        if ($message->content !== null) {
            $result['content'] = $message->content;
        }
        if ($message->name !== null) {
            $result['name'] = $message->name;
        }
        if ($message->toolCalls !== null) {
            $result['tool_calls'] = $message->toolCalls;
        }
        if ($message->toolCallId !== null) {
            $result['tool_call_id'] = $message->toolCallId;
        }

        return $result;
    }

    public static function fromArray(array $data): Message
    {
        return new Message(
            (string)($data['role'] ?? ''),
            isset($data['content']) ? (string)$data['content'] : null,
            isset($data['name']) ? (string)$data['name'] : null,
            isset($data['tool_calls']) && is_array($data['tool_calls']) ? $data['tool_calls'] : null,
            isset($data['tool_call_id']) ? (string)$data['tool_call_id'] : null
        );
    }
}
