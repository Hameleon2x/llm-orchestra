<?php

namespace Hameleon2x\Llm\Provider;

/**
 * Шлюз OpenRouter: OpenAI-совместимый API поверх каталога сторонних моделей.
 *
 * Полезные для него поля payload задаются в каталоге через extraParams — `plugins` для веб-поиска,
 * `provider` для выбора апстрима, `transforms` для сжатия контекста.
 */
class OpenRouterProvider extends OpenAiProvider
{
    public function name(): string
    {
        return 'OpenRouter';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://openrouter.ai/api';
    }
}
