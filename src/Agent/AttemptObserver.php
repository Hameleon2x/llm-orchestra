<?php

namespace Hameleon2x\Llm\Agent;

use Hameleon2x\Llm\Agent\Enum\Event;
use Hameleon2x\Llm\Dto\AttemptLog;

/**
 * Перевод попыток Orchestra в события агентского цикла.
 *
 * Помнит модель предыдущей попытки: смена ключа между попытками одного запроса и есть переключение
 * по фолбэку. Память живёт в пределах одного обращения к модели — перед каждым запросом вызывается
 * reset(), иначе возврат к запрошенной модели на следующем обороте (stickyFallback = false)
 * выглядел бы как ещё одно переключение.
 */
final class AttemptObserver
{
    /** @var callable */
    private $emit;

    private ?string $seenModel = null;

    /**
     * @param callable $emit function(string $event, string $content, array $meta): void
     */
    public function __construct(callable $emit)
    {
        $this->emit = $emit;
    }

    /**
     * Забыть модель предыдущего запроса. Вызывается перед каждым обращением к модели.
     */
    public function reset(): void
    {
        $this->seenModel = null;
    }

    public function __invoke(AttemptLog $attempt): void
    {
        // Модель запоминаем до отправки события: иначе сбой приёмника заставил бы прислать
        // одно и то же переключение ещё раз на следующей попытке.
        $previousModel = $this->seenModel;
        $this->seenModel = $attempt->modelKey;

        if ($previousModel !== null && $attempt->modelKey !== $previousModel) {
            ($this->emit)(Event::MODEL_FALLBACK, $attempt->modelKey, [
                'from' => $previousModel,
                'to'   => $attempt->modelKey,
            ]);
        }

        if ($attempt->success || $attempt->error === null) {
            return;
        }

        ($this->emit)(Event::ATTEMPT_FAILED, $attempt->error->category, [
            'model'        => $attempt->modelKey,
            'provider'     => $attempt->providerKey,
            'attempt'      => $attempt->attempt,
            'max_attempts' => $attempt->maxAttempts,
            'category'     => $attempt->error->category,
            'message'      => $attempt->error->message,
            'will_retry'   => $attempt->willRetry,
            'delay'        => $attempt->nextDelay,
        ]);
    }
}
