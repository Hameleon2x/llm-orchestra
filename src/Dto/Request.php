<?php

namespace Hameleon2x\Llm\Dto;

use Hameleon2x\Llm\Config\GenerationParams;

/**
 * Что мы просим у модели: сообщения, инструменты и — при необходимости — переопределения
 * параметров на этот конкретный вызов.
 *
 * Модель здесь не указывается: её выбирает вызывающий, передавая ключ каталога в
 * Orchestra::execute(). Так один и тот же запрос можно выполнить любой моделью, включая
 * переключение на следующую при сбое.
 */
final class Request
{
    /** @var Message[] */
    public array $messages;

    /** @var ToolDefinition[]|null */
    public ?array $tools;

    /** @var string|array|null 'auto', 'required', 'none' или конкретная функция */
    public $toolChoice;

    /** Переопределение параметров генерации на этот вызов. Сильнее модели и каталога. */
    public ?GenerationParams $params = null;

    /** Дополнительные поля payload на этот вызов (например, session_id прогона). */
    public array $extraParams = [];

    /** Дополнительные заголовки на этот вызов. */
    public array $headers = [];

    /**
     * @param Message[]             $messages
     * @param ToolDefinition[]|null $tools
     * @param string|array|null     $toolChoice
     */
    public function __construct(array $messages, ?array $tools = null, $toolChoice = null)
    {
        $this->messages = $messages;
        $this->tools = $tools;
        $this->toolChoice = $toolChoice;
    }

    /**
     * Системный промт + сообщение пользователя.
     */
    public static function simple(string $systemPrompt, string $userPrompt): self
    {
        return new self([
            Message::system($systemPrompt),
            Message::user($userPrompt),
        ]);
    }

    /**
     * Готовая история сообщений.
     *
     * @param Message[] $messages
     */
    public static function messages(array $messages): self
    {
        return new self($messages);
    }

    /**
     * История с инструментами.
     *
     * @param Message[]         $messages
     * @param ToolDefinition[]  $tools
     * @param string|array|null $toolChoice
     */
    public static function withTools(array $messages, array $tools, $toolChoice = 'auto'): self
    {
        return new self($messages, $tools, $toolChoice);
    }

    public function setParams(GenerationParams $params): self
    {
        $this->params = $params;

        return $this;
    }

    public function setTemperature(float $temperature): self
    {
        $this->ensureParams()->temperature = $temperature;

        return $this;
    }

    public function setTopP(float $topP): self
    {
        $this->ensureParams()->topP = $topP;

        return $this;
    }

    public function setMaxTokens(int $maxTokens): self
    {
        $this->ensureParams()->maxTokens = $maxTokens;

        return $this;
    }

    public function setSeed(int $seed): self
    {
        $this->ensureParams()->seed = $seed;

        return $this;
    }

    /**
     * Дополнительные поля payload на этот вызов. Сливаются поверх полей провайдера и модели;
     * стандартные поля (model, messages, temperature, top_p, max_tokens, tools, tool_choice, seed)
     * перезаписать нельзя — для них есть параметры генерации.
     *
     * @param array<string, mixed> $extraParams
     */
    public function setExtraParams(array $extraParams): self
    {
        $this->extraParams = $extraParams;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    private function ensureParams(): GenerationParams
    {
        if ($this->params === null) {
            $this->params = new GenerationParams();
        }

        return $this->params;
    }
}
