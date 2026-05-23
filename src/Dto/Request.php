<?php

namespace Hameleon2x\Llm\Dto;

/**
 * Запрос к LLM.
 */
class Request
{
    /** @var Message[] */
    public array $messages;

    /** @var ToolDefinition[]|null */
    public ?array $tools = null;

    /** @var string|array|null 'auto', 'required', 'none' или конкретная тулза */
    public $toolChoice;

    public ?float $temperature = null;
    public ?float $topP = null;
    public ?int $maxTokens = null;

    /** Переопределение модели для конкретного запроса */
    public ?string $model = null;

    /** Seed для детерминированной генерации */
    public ?int $seed = null;

    /** Плагины OpenRouter (напр. web search) */
    public ?array $plugins = null;

    /**
     * @param Message[]             $messages
     * @param ToolDefinition[]|null $tools
     * @param string|array|null     $toolChoice
     */
    public function __construct(
        array   $messages,
        ?array  $tools = null,
                $toolChoice = null,
        ?float  $temperature = null,
        ?float  $topP = null,
        ?int    $maxTokens = null,
        ?string $model = null,
        ?int    $seed = null
    )
    {
        $this->messages = $messages;
        $this->tools = $tools;
        $this->toolChoice = $toolChoice;
        $this->temperature = $temperature;
        $this->topP = $topP;
        $this->maxTokens = $maxTokens;
        $this->model = $model;
        $this->seed = $seed;
    }

    /**
     * Простой запрос: system + user.
     */
    public static function simple(string $systemPrompt, string $userPrompt): self
    {
        return new self([
            Message::system($systemPrompt),
            Message::user($userPrompt),
        ]);
    }

    /**
     * Запрос только с готовой историей сообщений.
     */
    public static function messages(array $messages): self
    {
        return new self($messages);
    }

    /**
     * Запрос с инструментами.
     *
     * @param string|array|null $toolChoice
     */
    public static function withTools(array $messages, array $tools, $toolChoice = 'auto'): self
    {
        return new self($messages, $tools, $toolChoice);
    }

    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    public function setTopP(float $topP): self
    {
        $this->topP = $topP;
        return $this;
    }

    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function setSeed(int $seed): self
    {
        $this->seed = $seed;
        return $this;
    }

    /**
     * Плагины OpenRouter (напр. web search).
     */
    public function setPlugins(array $plugins): self
    {
        $this->plugins = $plugins;
        return $this;
    }
}
