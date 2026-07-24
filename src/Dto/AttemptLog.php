<?php

namespace Hameleon2x\Llm\Dto;

use Hameleon2x\Llm\Error\ErrorInfo;

/**
 * Одна попытка вызова модели: какая модель, какая по счёту попытка, чем кончилась.
 *
 * Журнал попыток отвечает на вопрос «почему ответила не та модель, которую выбирали» — и в логах,
 * и в интерфейсе, без реконструкции по текстам ошибок.
 */
final class AttemptLog
{
    public string $modelKey;
    public string $providerKey;

    /** Номер попытки этой моделью, с единицы. */
    public int $attempt;

    /**
     * Сколько попыток этой моделью всего допускает политика — с учётом категории этой ошибки.
     * Интерфейсу это нужно, чтобы писать «повтор 2 из 3», а не просто «повтор».
     */
    public int $maxAttempts = 1;

    public bool $success;

    /** Ошибка попытки; null при успехе. */
    public ?ErrorInfo $error;

    /** Длительность попытки, секунды. */
    public float $latency;

    /** Пауза перед этой попыткой, секунды. */
    public float $delayBefore;

    /** Будет ли повтор той же моделью после этой попытки. */
    public bool $willRetry = false;

    /** Пауза перед следующей попыткой, секунды (когда willRetry). */
    public float $nextDelay = 0.0;

    public function __construct(
        string     $modelKey,
        string     $providerKey,
        int        $attempt,
        bool       $success,
        ?ErrorInfo $error = null,
        float      $latency = 0.0,
        float      $delayBefore = 0.0
    ) {
        $this->modelKey = $modelKey;
        $this->providerKey = $providerKey;
        $this->attempt = $attempt;
        $this->success = $success;
        $this->error = $error;
        $this->latency = $latency;
        $this->delayBefore = $delayBefore;
    }

    public function toArray(): array
    {
        return [
            'model'       => $this->modelKey,
            'provider'    => $this->providerKey,
            'attempt'     => $this->attempt,
            'maxAttempts' => $this->maxAttempts,
            'success'     => $this->success,
            'latency'     => round($this->latency, 3),
            'delayBefore' => $this->delayBefore,
            'willRetry'   => $this->willRetry,
            'nextDelay'   => $this->nextDelay,
            'error'       => $this->error !== null ? $this->error->toArray() : null,
        ];
    }
}
