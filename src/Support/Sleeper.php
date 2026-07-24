<?php

namespace Hameleon2x\Llm\Support;

/**
 * Пауза через usleep — реализация по умолчанию.
 */
final class Sleeper implements SleeperInterface
{
    public function sleep(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        usleep((int)round($seconds * 1_000_000));
    }
}
