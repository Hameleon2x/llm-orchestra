<?php

namespace Hameleon2x\Llm\Dto;

/**
 * Потребление одного вызова модели и накопитель за прогон.
 *
 * Кроме трёх обязательных счётчиков хранит то, что провайдеры отдают всё чаще: кешированные и
 * «думающие» токены и фактическую стоимость. Стоимость от провайдера точнее расчёта по ценам
 * каталога, поэтому если она пришла — используем её.
 *
 * Разбивка byModel нужна из-за фолбэка: в одном прогоне могут отработать две модели с разной ценой.
 */
final class Usage
{
    /** Сколько вызовов модели учтено (для одного ответа — 1). */
    public int $calls = 0;

    public int $promptTokens = 0;
    public int $completionTokens = 0;
    public int $totalTokens = 0;

    /** Токены промпта, обслуженные из кеша провайдера. */
    public int $cachedTokens = 0;

    /** Токены рассуждений reasoning-моделей (входят в completionTokens). */
    public int $reasoningTokens = 0;

    /** Фактическая стоимость в долларах, если провайдер её вернул. */
    public ?float $cost = null;

    /**
     * Разбивка по моделям прогона.
     *
     * @var array<string, Usage>
     */
    public array $byModel = [];

    /**
     * Учесть потребление $other, отнеся его к модели $modelKey.
     */
    public function add(self $other, string $modelKey = ''): void
    {
        $this->calls += max(1, $other->calls);
        $this->promptTokens += $other->promptTokens;
        $this->completionTokens += $other->completionTokens;
        $this->totalTokens += $other->totalTokens;
        $this->cachedTokens += $other->cachedTokens;
        $this->reasoningTokens += $other->reasoningTokens;

        if ($other->cost !== null) {
            $this->cost = ($this->cost ?? 0.0) + $other->cost;
        }

        if ($modelKey === '') {
            return;
        }

        if (!isset($this->byModel[$modelKey])) {
            $this->byModel[$modelKey] = new self();
        }

        // Срез считается тем же кодом, но уже без разбивки: вложенных срезов у него не бывает.
        $this->byModel[$modelKey]->add($other);
    }

    public function toArray(): array
    {
        $result = [
            'calls'            => $this->calls,
            'promptTokens'     => $this->promptTokens,
            'completionTokens' => $this->completionTokens,
            'totalTokens'      => $this->totalTokens,
        ];

        if ($this->cachedTokens > 0) {
            $result['cachedTokens'] = $this->cachedTokens;
        }
        if ($this->reasoningTokens > 0) {
            $result['reasoningTokens'] = $this->reasoningTokens;
        }
        if ($this->cost !== null) {
            $result['cost'] = $this->cost;
        }
        if ($this->byModel !== []) {
            $result['byModel'] = [];
            foreach ($this->byModel as $modelKey => $usage) {
                $result['byModel'][$modelKey] = $usage->toArray();
            }
        }

        return $result;
    }
}
