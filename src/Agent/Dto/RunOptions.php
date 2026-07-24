<?php

namespace Hameleon2x\Llm\Agent\Dto;

use Hameleon2x\Llm\Config\ErrorPolicy;
use Hameleon2x\Llm\Config\GenerationParams;
use Hameleon2x\Llm\Exception\LlmConfigException;
use Hameleon2x\Llm\Tool\ToolArgsGuard;

/**
 * Параметры одного прогона Runner: какой моделью работать, докуда считать лимиты и что делать
 * с ошибками.
 *
 * Это аргумент вызова, а не конфигурация приложения: объект создаётся на каждый прогон и живёт
 * ровно столько же. Значения по умолчанию для всех прогонов задаются секцией `defaultRun`
 * каталога — `Registry::runOptions()` отдаёт готовый объект, который остаётся поправить под
 * конкретный запуск.
 *
 * Модель задаётся ключом каталога. Цепочка фолбэка и политика ошибок по умолчанию берутся из
 * каталога — переопределять их здесь нужно редко.
 */
final class RunOptions
{
    /** Ключ модели каталога. null — модель каталога по умолчанию. */
    public ?string $model = null;

    /**
     * Цепочка фолбэка на этот прогон. null — цепочка каталога.
     *
     * @var string[]|null
     */
    public ?array $fallback = null;

    /**
     * Сколько переключений на запасную модель разрешено на одно обращение к модели: счёт идёт в
     * пределах оборота цикла и начинается заново на следующем. null — значение каталога.
     */
    public ?int $maxSwitches = null;

    /** Политика ошибок на этот прогон. null — политика модели или каталога. */
    public ?ErrorPolicy $policy = null;

    /** Продолжать прогон на той модели, которая ответила после переключения. */
    public bool $stickyFallback = true;

    /**
     * Лимит оборотов цикла: один оборот — один вызов модели.
     *
     * Держится выше maxToolCalls намеренно: тогда первым срабатывает лимит вызовов, и прогон
     * заканчивается итоговым ответом модели, а не служебной заглушкой об исчерпании оборотов.
     * Обороту, который не запросил ни одного инструмента, лимит не нужен — такой ход завершает цикл.
     */
    public int $maxTurns = 40;

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

    /**
     * Показывать ли модели сообщение исключения, вылетевшего из инструмента.
     *
     * По умолчанию нет: такие тексты пишут для разработчика. Сообщение `PDOException` несёт полный
     * SQL со значениями параметров, чужая библиотека — путь на диске; всё это уходит провайдеру и
     * повторяется на каждом следующем обороте. Модели при этом обычно нечего с ним делать.
     *
     * Включайте, когда инструменты бросают осмысленные для модели исключения (валидация, «не
     * найдено») и оборачивать каждый вызов в свой try/catch не хочется. Сообщение обрезается до
     * TOOL_EXCEPTION_MAX_LENGTH символов. Штатный способ сказать что-то модели — `Result::error()`.
     */
    public bool $exposeToolExceptions = false;

    /** До скольких символов обрезается сообщение исключения, когда его показывают модели. */
    public const TOOL_EXCEPTION_MAX_LENGTH = 300;

    /** Сообщение пользователя, добавляемое при исчерпании лимита вызовов инструментов. */
    public string $limitNudgeMessage = 'Лимит обращений к инструментам исчерпан. Дай итоговый ответ на основе уже полученных данных. Если данных не хватает — перечисли, что именно нужно запросить.';

    /** Ответ, когда после добивки модель ничего не вернула. */
    public string $limitFallbackText = 'Не удалось завершить за допустимое число вызовов инструментов.';

    /** Ответ, когда исчерпан лимит оборотов цикла. */
    public string $turnsExhaustedText = 'Не удалось завершить за допустимое число итераций.';

    /** Что вернётся модели вместо результата вызова, отклонённого исчерпанным лимитом вызовов. */
    public string $toolLimitReachedText = 'Достигнут лимит вызовов инструментов за прогон.';

    /** Что вернётся модели вместо результата инструмента, упавшего с исключением. */
    public string $toolFailedText = 'Инструмент завершился внутренней ошибкой. Повторять вызов с теми же аргументами бессмысленно.';

    /** Начало ответа, когда сообщение исключения показывают модели ($exposeToolExceptions). */
    public string $toolFailedPrefix = 'Инструмент завершился ошибкой: ';

    /** Что вернётся модели, если результат инструмента не удалось закодировать в JSON. */
    public string $encodeFailedText = 'Результат инструмента не удалось сериализовать в JSON.';

    /**
     * Ключ, под который убирается результат-список, когда в него добавляется пояснение первого
     * вызова инструмента: список не может принять ключ, а терять пояснение нельзя — его дают ровно
     * один раз за диалог. Результат-объект заворачивать не нужно, он получает пояснение соседним
     * ключом.
     */
    public string $firstUseResultKey = 'result';

    public function __construct()
    {
        $this->params = new GenerationParams();
        $this->toolArgsGuard = ToolArgsGuard::default();
    }

    /** Ключи, которые можно задать конфигом. Остальные поля — только из кода. */
    private const CONFIGURABLE = [
        'model', 'fallback', 'maxSwitches', 'policy', 'stickyFallback',
        'maxTurns', 'maxToolCalls', 'deadlineSeconds',
        'params', 'extraParams', 'toolChoice', 'toolArgsGuard', 'exposeToolExceptions',
        'limitNudgeMessage', 'limitFallbackText', 'turnsExhaustedText',
        'toolLimitReachedText', 'toolFailedText', 'toolFailedPrefix', 'encodeFailedText',
        'firstUseResultKey',
    ];

    /**
     * Опции из массива конфигурации — обычно из секции `defaultRun` каталога.
     *
     * Незаданные ключи остаются со значениями по умолчанию класса. Неизвестный ключ — ошибка:
     * опечатка вроде `maxTurn` иначе молча оставила бы прогону дефолтные сорок оборотов.
     */
    public static function fromArray(array $config): self
    {
        $options = new self();

        foreach (array_keys($config) as $key) {
            if (!in_array((string)$key, self::CONFIGURABLE, true)) {
                throw new LlmConfigException(
                    "Опции прогона: неизвестный ключ «{$key}». Допустимы: " . implode(', ', self::CONFIGURABLE) . '.'
                );
            }
        }

        if (isset($config['model'])) {
            $options->model = (string)$config['model'];
        }
        if (isset($config['fallback']) && is_array($config['fallback'])) {
            $options->fallback = array_values($config['fallback']);
        }
        if (isset($config['maxSwitches'])) {
            $options->maxSwitches = max(0, (int)$config['maxSwitches']);
        }
        if (isset($config['policy']) && is_array($config['policy'])) {
            $options->policy = ErrorPolicy::fromArray($config['policy']);
        }
        if (isset($config['stickyFallback'])) {
            $options->stickyFallback = (bool)$config['stickyFallback'];
        }
        if (isset($config['maxTurns'])) {
            $options->maxTurns = max(0, (int)$config['maxTurns']);
        }
        if (isset($config['maxToolCalls'])) {
            $options->maxToolCalls = max(0, (int)$config['maxToolCalls']);
        }
        if (array_key_exists('deadlineSeconds', $config)) {
            $options->deadlineSeconds = $config['deadlineSeconds'] !== null
                ? (float)$config['deadlineSeconds']
                : null;
        }
        if (isset($config['params']) && is_array($config['params'])) {
            $options->params = GenerationParams::fromArray($config['params']);
        }
        if (isset($config['extraParams']) && is_array($config['extraParams'])) {
            $options->extraParams = $config['extraParams'];
        }
        if (isset($config['toolChoice'])) {
            $options->toolChoice = $config['toolChoice'];
        }
        // Проверку аргументов конфигом можно только выключить: свои правила задаются объектом.
        if (array_key_exists('toolArgsGuard', $config) && empty($config['toolArgsGuard'])) {
            $options->toolArgsGuard = null;
        }
        if (isset($config['exposeToolExceptions'])) {
            $options->exposeToolExceptions = (bool)$config['exposeToolExceptions'];
        }

        foreach (
            [
                'limitNudgeMessage', 'limitFallbackText', 'turnsExhaustedText',
                'toolLimitReachedText', 'toolFailedText', 'toolFailedPrefix', 'encodeFailedText',
                'firstUseResultKey',
            ] as $text
        ) {
            if (isset($config[$text])) {
                $options->{$text} = (string)$config[$text];
            }
        }

        return $options;
    }
}
