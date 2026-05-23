<?php

namespace Hameleon2x\Llm\Agent\Dto;

/**
 * Параметры прогона агентского цикла Runner: лимиты, параметры генерации,
 * тексты-заглушки на случай исчерпания лимитов.
 */
class Config
{
    public int $maxTurns = 10;
    public int $maxToolCalls = 30;

    /** null — берётся дефолт провайдера */
    public ?float $temperature = null;

    /** null — берётся дефолт провайдера */
    public ?int $maxTokens = null;

    /** @var string|array 'auto', 'required', 'none' или конкретная тулза */
    public $toolChoice = 'auto';

    /** Плагины OpenRouter (напр. web search); null — без плагинов */
    public ?array $plugins = null;

    /** Сообщение пользователя, добавляемое при исчерпании лимита вызовов тулз */
    public string $limitNudgeMessage = 'Лимит обращений к инструментам исчерпан. Дай итоговый ответ на основе уже полученных данных. Если данных не хватает — перечисли, что именно нужно запросить.';

    /** Ответ, когда после добивки модель ничего не вернула */
    public string $limitFallbackText = 'Не удалось завершить за допустимое число вызовов инструментов.';

    /** Ответ, когда исчерпан лимит оборотов цикла */
    public string $turnsExhaustedText = 'Не удалось завершить за допустимое число итераций.';
}
