<?php

namespace Hameleon2x\Llm\Config;

use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;

/**
 * Что делать со сбоем: сколько раз повторить ту же модель и передавать ли работу следующей
 * модели цепочки.
 *
 * Уровень повторов ровно один — этот. Транспорт своих циклов не крутит, поэтому время ожидания
 * при сбое предсказуемо и целиком описано здесь.
 */
final class ErrorPolicy
{
    /** Передать работу следующей модели цепочки фолбэка. */
    public const THEN_FALLBACK = 'fallback';

    /** Вернуть ошибку, ничего больше не пробуя. */
    public const THEN_STOP = 'stop';

    /** Сколько дополнительных попыток той же моделью (0 — не повторять). */
    public int $retries = 2;

    /** Базовая пауза перед повтором, секунды. */
    public float $delay = 5.0;

    /** Множитель паузы на каждой следующей попытке: 5с → 10с → 20с при backoff = 2. */
    public float $backoff = 2.0;

    /** Потолок одной паузы, секунды. */
    public float $maxDelay = 60.0;

    /** Поведение после исчерпания повторов: THEN_FALLBACK или THEN_STOP. */
    public string $then = self::THEN_FALLBACK;

    /**
     * Переопределения по категориям: `[ErrorCategory::RATE_LIMIT => ['retries' => 3, 'delay' => 15]]`.
     * Действуют только на ту категорию, в которой заданы, и только на счётные поля: `retries`,
     * `delay`, `backoff`, `maxDelay`. Решение «переключаться или остановиться» категорией не
     * настраивается — для него есть `then` и `stopOn`.
     *
     * @var array<string, array>
     */
    public array $perCategory = [];

    /**
     * Категории, которые повторяем. Пустой массив — решает сама категория (см. ErrorCategory).
     *
     * @var string[]
     */
    public array $retryOn = [];

    /**
     * Категории, при которых не передаём работу следующей модели, даже если then = fallback.
     *
     * @var string[]
     */
    public array $stopOn = [];

    /**
     * Потолок времени на одну модель, секунды: запросы к ней плюс паузы между повторами. По его
     * исчерпании повторы этой моделью прекращаются и работа уходит следующей модели цепочки —
     * у неё отсчёт начинается заново.
     *
     * Уже идущий HTTP-запрос потолок не прерывает — это дело таймаута провайдера или модели, —
     * поэтому фактическое время модели может превысить его на длительность последнего запроса.
     * null — без потолка.
     *
     * Ограничение на весь вызов целиком, со всеми переключениями, задаётся отдельно:
     * `maxTotalWaitSeconds` каталога.
     */
    public ?float $maxWaitSeconds = null;

    /**
     * Собрать политику из конфига. Незаданные поля берут значения по умолчанию этого класса —
     * политика с другого уровня сюда не подмешивается.
     *
     * Уровни не сливаются намеренно: политика действует целиком с того уровня, где она задана
     * (модель → провайдер → каталог), поэтому по конфигу сразу видно, как поведёт себя вызов.
     */
    public static function fromArray(array $config): self
    {
        $policy = new self();

        if (isset($config['retries'])) {
            $policy->retries = max(0, (int)$config['retries']);
        }
        if (isset($config['delay'])) {
            $policy->delay = max(0.0, (float)$config['delay']);
        }
        if (isset($config['backoff'])) {
            $policy->backoff = max(1.0, (float)$config['backoff']);
        }
        if (isset($config['maxDelay'])) {
            $policy->maxDelay = max(0.0, (float)$config['maxDelay']);
        }
        if (isset($config['then'])) {
            $policy->then = (string)$config['then'] === self::THEN_STOP ? self::THEN_STOP : self::THEN_FALLBACK;
        }
        if (isset($config['perCategory']) && is_array($config['perCategory'])) {
            $policy->perCategory = self::normalizePerCategory($config['perCategory']);
        }
        if (isset($config['retryOn']) && is_array($config['retryOn'])) {
            $policy->retryOn = $config['retryOn'];
        }
        if (isset($config['stopOn']) && is_array($config['stopOn'])) {
            $policy->stopOn = $config['stopOn'];
        }
        if (array_key_exists('maxWaitSeconds', $config)) {
            $policy->maxWaitSeconds = $config['maxWaitSeconds'] !== null
                ? (float)$config['maxWaitSeconds']
                : null;
        }

        return $policy;
    }

    /**
     * Повторять ли сбой $error, если уже сделано $attempt попыток этой моделью.
     */
    public function shouldRetry(ErrorInfo $error, int $attempt): bool
    {
        if ($attempt > $this->valueFor($error->category, 'retries', $this->retries)) {
            return false;
        }

        if ($this->retryOn !== []) {
            return in_array($error->category, $this->retryOn, true);
        }

        return $error->retryable;
    }

    /**
     * Передавать ли работу следующей модели цепочки.
     */
    public function shouldFallback(ErrorInfo $error): bool
    {
        if ($this->then === self::THEN_STOP) {
            return false;
        }
        if (in_array($error->category, $this->stopOn, true)) {
            return false;
        }

        return ErrorCategory::isFallbackableByDefault($error->category);
    }

    /**
     * Пауза перед попыткой номер $attempt + 1 (нумерация попыток с единицы).
     */
    public function delayFor(string $category, int $attempt): float
    {
        $delay = (float)$this->valueFor($category, 'delay', $this->delay);
        $backoff = (float)$this->valueFor($category, 'backoff', $this->backoff);
        $maxDelay = (float)$this->valueFor($category, 'maxDelay', $this->maxDelay);

        return min($delay * ($backoff ** max(0, $attempt - 1)), $maxDelay);
    }

    /**
     * Привести переопределения по категориям к тем же типам и границам, что и поля политики.
     * Иначе опечатка в конфиге (`'retries' => 'много'`) сравнивалась бы со счётчиком попыток как
     * строка и повторы стали бы бесконечными.
     *
     * @param array<string, mixed> $perCategory
     * @return array<string, array>
     */
    private static function normalizePerCategory(array $perCategory): array
    {
        $normalized = [];

        foreach ($perCategory as $category => $overrides) {
            if (!is_array($overrides)) {
                continue;
            }

            $clean = [];
            if (isset($overrides['retries'])) {
                $clean['retries'] = max(0, (int)$overrides['retries']);
            }
            if (isset($overrides['delay'])) {
                $clean['delay'] = max(0.0, (float)$overrides['delay']);
            }
            if (isset($overrides['backoff'])) {
                $clean['backoff'] = max(1.0, (float)$overrides['backoff']);
            }
            if (isset($overrides['maxDelay'])) {
                $clean['maxDelay'] = max(0.0, (float)$overrides['maxDelay']);
            }

            if ($clean !== []) {
                $normalized[(string)$category] = $clean;
            }
        }

        return $normalized;
    }

    /**
     * Значение поля с учётом переопределения по категории.
     *
     * @return mixed
     */
    private function valueFor(string $category, string $field, $default)
    {
        $override = $this->perCategory[$category] ?? null;
        if (is_array($override) && array_key_exists($field, $override)) {
            return $override[$field];
        }

        return $default;
    }
}
