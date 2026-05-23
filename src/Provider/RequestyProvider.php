<?php

namespace Hameleon2x\Llm\Provider;

use Psr\Log\LoggerInterface;

/**
 * Провайдер Requesty.ai (OpenAI-совместимый API).
 */
class RequestyProvider extends OpenAiProvider
{
    public function __construct(
        string           $token,
        string           $model = 'openai/gpt-4.1-mini',
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
        $url = ($baseUrl !== null && $baseUrl !== '') ? $baseUrl : 'https://router.requesty.ai';
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
        return 'Requesty';
    }
}
