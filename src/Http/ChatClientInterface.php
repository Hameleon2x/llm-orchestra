<?php

namespace Hameleon2x\Llm\Http;

use Throwable;

/**
 * Клиент для OpenAI-совместимого Chat Completions API (один метод: запрос → ответ).
 */
interface ChatClientInterface
{
    /**
     * POST /v1/chat/completions с телом $params, вернуть сырое тело ответа (JSON).
     *
     * @param array $params model, messages, temperature, max_tokens, tools, tool_choice, seed, ...
     * @return string JSON-ответ
     * @throws Throwable при сетевой ошибке или не-2xx
     */
    public function chat(array $params): string;
}
