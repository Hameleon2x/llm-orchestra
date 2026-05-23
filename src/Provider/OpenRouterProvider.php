<?php

namespace Hameleon2x\Llm\Provider;

use Psr\Log\LoggerInterface;

/**
 * Провайдер OpenRouter (OpenAI-совместимый API).
 */
class OpenRouterProvider extends OpenAiProvider
{
    public function __construct(
        string           $token,
        string           $model = 'deepseek/deepseek-chat-v3-0324:free',
        ?string          $baseUrl = null,
        ?float           $temperature = null,
        ?float           $topP = null,
        ?int             $maxTokens = null,
        int              $retryAttempts = 3,
        int              $timeout = 30,
        int              $priority = 999,
        ?array           $supportedModels = null,
        ?LoggerInterface $logger = null
    )
    {
        $url = ($baseUrl !== null && $baseUrl !== '') ? $baseUrl : 'https://openrouter.ai/api';
        parent::__construct(
            $token,
            $model,
            $url,
            $temperature,
            $topP,
            $maxTokens,
            $retryAttempts,
            $timeout,
            $priority,
            $supportedModels,
            $logger
        );
    }

    public function getName(): string
    {
        return 'OpenRouter';
    }
}
