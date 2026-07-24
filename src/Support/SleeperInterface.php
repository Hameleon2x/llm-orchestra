<?php

namespace Hameleon2x\Llm\Support;

/**
 * Пауза между попытками. Отдельный интерфейс, потому что ждать умеет не только `usleep`:
 * в тестах пауза пропускается, в веб-контексте приложение может отдать управление своему циклу.
 */
interface SleeperInterface
{
    public function sleep(float $seconds): void;
}
