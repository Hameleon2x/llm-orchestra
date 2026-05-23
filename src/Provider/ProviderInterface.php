<?php

namespace Hameleon2x\Llm\Provider;

use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Exception\LlmException;

/**
 * Интерфейс провайдера LLM.
 */
interface ProviderInterface
{
    /**
     * @throws LlmException
     */
    public function execute(Request $request): Response;

    public function getName(): string;

    /** Приоритет провайдера: меньше = выше. */
    public function getPriority(): int;
}
