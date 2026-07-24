<?php

namespace Hameleon2x\Llm\Agent\Dto;

use Hameleon2x\Llm\Config\ErrorPolicy;
use Hameleon2x\Llm\Config\GenerationParams;
use Hameleon2x\Llm\Tool\ToolArgsGuard;

/**
 * Параметры одного прогона Runner: какой моделью работать, докуда считать лимиты и что делать
 * с ошибками.
 *
 * Модель задаётся ключом каталога. Цепочка фолбэка и политика ошибок по умолчанию берутся из
 * каталога — переопределять их здесь нужно редко.
 */
final class Config
{
    /** Ключ модели каталога. null — модель каталога по умолчанию. */
    public ?string $model = null;

    /**
     * Цепочка фолбэка на этот прогон. null — цепочка каталога.
     *
     * @var string[]|null
     */
    public ?array $fallback = null;

    /** Политика ошибок на этот прогон. null — политика модели или каталога. */
    public ?ErrorPolicy $policy = null;

    /** Продолжать прогон на той модели, которая ответила после переключения. */
    public bool $stickyFallback = true;

    /** Лимит оборотов цикла: один оборот — один вызов модели. */
    public int $maxTurns = 10;

    /** Лимит вызовов инструментов за весь прогон. */
    public int $maxToolCalls = 30;

    /** Предельная длительность прогона, секунды. null — без ограничения. */
    public ?float $deadlineSeconds = null;

    /** Параметры генерации на прогон: перекрывают модель и каталог. */
    public GenerationParams $params;

    /** Дополнительные поля payload на каждый вызов прогона (например, session_id). */
    public array $extraParams = [];

    /** @var string|array 'auto', 'required', 'none' или конкретный инструмент */
    public $toolChoice = 'auto';

    /**
     * Проверка аргументов инструментов на протёкшую разметку формата вызова. null — не проверять.
     * Включена по умолчанию: пропущенная утечка означает исполнение инструмента на неполных данных,
     * а ложное срабатывание стоит одного переотправленного вызова.
     */
    public ?ToolArgsGuard $toolArgsGuard;

    /** Сообщение пользователя, добавляемое при исчерпании лимита вызовов инструментов. */
    public string $limitNudgeMessage = 'Лимит обращений к инструментам исчерпан. Дай итоговый ответ на основе уже полученных данных. Если данных не хватает — перечисли, что именно нужно запросить.';

    /** Ответ, когда после добивки модель ничего не вернула. */
    public string $limitFallbackText = 'Не удалось завершить за допустимое число вызовов инструментов.';

    /** Ответ, когда исчерпан лимит оборотов цикла. */
    public string $turnsExhaustedText = 'Не удалось завершить за допустимое число итераций.';

    public function __construct()
    {
        $this->params = new GenerationParams();
        $this->toolArgsGuard = ToolArgsGuard::default();
    }
}
