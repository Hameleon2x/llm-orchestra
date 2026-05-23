<?php

namespace Hameleon2x\Llm\Agent\Dto;

use Hameleon2x\Llm\Dto\Response;

/**
 * Накопитель статистики LLM-вызовов за один прогон Runner: количество запросов
 * к модели и суммарное потребление токенов. Считаются все вызовы, включая финальную
 * добивку — это нужно для логирования стоимости прогона.
 */
final class Usage
{
    /** Сколько раз делали запрос к модели за прогон (включая финальную добивку). */
    public int $llmCalls = 0;

    public int $promptTokens = 0;
    public int $completionTokens = 0;
    public int $totalTokens = 0;

    /**
     * Учесть очередной ответ модели. Безопасно вызывать и для неуспешных ответов —
     * метаданные usage у провайдеров приходят и в этом случае (могут быть нули).
     */
    public function add(Response $response): void
    {
        $this->llmCalls++;
        $this->promptTokens     += $response->getPromptTokens();
        $this->completionTokens += $response->getCompletionTokens();
        $this->totalTokens      += $response->getTotalTokens();
    }
}
