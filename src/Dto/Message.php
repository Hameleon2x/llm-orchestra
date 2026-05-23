<?php

namespace Hameleon2x\Llm\Dto;

use Hameleon2x\Llm\Enum\Role;

/**
 * Сообщение в диалоге с LLM.
 */
class Message
{
    public string $role;
    public ?string $content;
    public ?string $name;
    public ?array $toolCalls;
    public ?string $toolCallId;

    public function __construct(
        string  $role,
        ?string $content = null,
        ?string $name = null,
        ?array  $toolCalls = null,
        ?string $toolCallId = null
    )
    {
        $this->role = $role;
        $this->content = $content;
        $this->name = $name;
        $this->toolCalls = $toolCalls;
        $this->toolCallId = $toolCallId;
    }

    public static function system(string $content): self
    {
        return new self(Role::SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(Role::USER, $content);
    }

    public static function assistant(string $content, ?array $toolCalls = null): self
    {
        return new self(Role::ASSISTANT, $content, null, $toolCalls);
    }

    public static function tool(string $toolCallId, string $content): self
    {
        return new self(Role::TOOL, $content, null, null, $toolCallId);
    }
}
