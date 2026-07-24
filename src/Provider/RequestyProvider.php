<?php

namespace Hameleon2x\Llm\Provider;

/**
 * Шлюз Requesty: OpenAI-совместимый API поверх каталога сторонних моделей.
 */
class RequestyProvider extends OpenAiProvider
{
    public function name(): string
    {
        return 'Requesty';
    }

    protected function defaultBaseUrl(): string
    {
        return 'https://router.requesty.ai';
    }
}
