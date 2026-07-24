<?php

namespace Hameleon2x\Llm;

use Hameleon2x\Llm\Config\ErrorPolicy;
use Hameleon2x\Llm\Config\ModelDefinition;
use Hameleon2x\Llm\Dto\AttemptLog;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;
use Hameleon2x\Llm\Error\ErrorMapper;
use Hameleon2x\Llm\Exception\LlmConfigException;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Provider\ProviderInterface;
use Hameleon2x\Llm\Support\Sleeper;
use Hameleon2x\Llm\Support\SleeperInterface;
use ArrayObject;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Исполнитель запросов: выбирает модель по ключу каталога, повторяет её при сбое по политике и —
 * если повторы не помогли — передаёт работу следующей модели цепочки фолбэка.
 *
 * Цепочка одна на каталог и плоская: у моделей нет собственных списков продолжения, поэтому не
 * возникает вопроса, чей список главнее. Уже опробованные модели пропускаются, число переключений
 * ограничено maxSwitches.
 *
 * Наружу ничего не бросает: результат — Response, у которого либо content, либо error с категорией.
 *
 * ```php
 * $orchestra = new Orchestra(Registry::fromArray($config));
 * $response  = $orchestra->execute(Request::simple('Отвечай кратко.', 'Что такое PHP?'));
 * echo $response->isSuccess() ? $response->content : $response->error->category;
 * ```
 */
final class Orchestra
{
    private Registry $registry;

    private LoggerInterface $logger;

    private SleeperInterface $sleeper;

    /**
     * Инстансы провайдеров по ключу каталога.
     *
     * Хранятся в объекте намеренно: копии исполнителя (`with*`) разделяют этот кеш, поэтому
     * переопределение бюджета на каждый оборот не пересоздаёт провайдера и его HTTP-клиент —
     * иначе фабрика клиента с пулом соединений вызывалась бы на каждое обращение к модели.
     *
     * @var ArrayObject<string, ProviderInterface>
     */
    private ArrayObject $providers;

    /** Политика, перекрывающая политику каталога и моделей. */
    private ?ErrorPolicy $policyOverride = null;

    /** @var string[]|null цепочка, перекрывающая цепочку каталога */
    private ?array $fallbackOverride = null;

    private ?int $maxSwitchesOverride = null;

    /** Потолок времени на вызов, перекрывающий каталожный. */
    private ?float $totalWaitOverride = null;

    /** @var callable|null function(AttemptLog $attempt): void */
    private $observer = null;

    public function __construct(
        Registry          $registry,
        ?LoggerInterface  $logger = null,
        ?SleeperInterface $sleeper = null
    ) {
        $this->registry = $registry;
        $this->logger = $logger ?? new NullLogger();
        $this->sleeper = $sleeper ?? new Sleeper();
        $this->providers = new ArrayObject();
    }

    public function registry(): Registry
    {
        return $this->registry;
    }

    /**
     * Копия с другой политикой ошибок — на случай, когда конкретной задаче нужны свои повторы.
     */
    public function withPolicy(ErrorPolicy $policy): self
    {
        $clone = clone $this;
        $clone->policyOverride = $policy;

        return $clone;
    }

    /**
     * Копия с другой цепочкой фолбэка.
     *
     * @param string[] $chain ключи моделей в порядке эскалации
     */
    public function withFallback(array $chain, ?int $maxSwitches = null): self
    {
        $clone = clone $this;
        $clone->fallbackOverride = array_values($chain);
        $clone->maxSwitchesOverride = $maxSwitches;

        return $clone;
    }

    /**
     * Копия с другим потолком времени на весь вызов — когда вызывающий знает, сколько времени у
     * него осталось. Так агентский цикл проецирует свой дедлайн на каждое обращение к модели.
     */
    public function withTotalWaitSeconds(?float $seconds): self
    {
        $clone = clone $this;
        $clone->totalWaitOverride = $seconds;

        return $clone;
    }

    /**
     * Копия, сообщающая о каждой попытке. Нужна для прогресса в интерфейсе: повторы и переключения
     * видны сразу, а не по журналу в конце.
     *
     * @param callable $observer function(AttemptLog $attempt): void
     */
    public function withObserver(callable $observer): self
    {
        $clone = clone $this;
        $clone->observer = $observer;

        return $clone;
    }

    /**
     * Выполнить запрос.
     *
     * @param string|null $modelKey ключ модели каталога; null — модель каталога по умолчанию
     */
    public function execute(Request $request, ?string $modelKey = null): Response
    {
        try {
            // Неизвестный ключ не ошибка — он подменяется моделью каталога по умолчанию, но молча
            // делать этого нельзя: в базе приложения мог остаться идентификатор, которого больше нет.
            if ($modelKey !== null && $modelKey !== '' && !$this->registry->has($modelKey)) {
                $this->logger->warning('LLM unknown model key, falling back to the default model', [
                    'requested' => $modelKey,
                    'default'   => $this->registry->defaultModelKey(),
                ]);
            }

            $model = $this->registry->model($this->registry->normalize($modelKey));
        } catch (LlmConfigException $e) {
            return Response::failed($e->info());
        }

        $chain = $this->fallbackOverride ?? $this->registry->fallbackChain();
        $maxSwitches = $this->maxSwitchesOverride ?? $this->registry->maxSwitches();
        $totalWait = $this->totalWaitOverride ?? $this->registry->maxTotalWaitSeconds();

        $attempts = [];
        $attempted = [];
        $switches = 0;
        $startedAt = microtime(true);
        $error = null;

        while (true) {
            $attempted[$model->key] = true;
            $policy = $this->policyOverride ?? $this->registry->policyFor($model);

            $outcome = $this->runModel($request, $model, $policy, $attempts, $startedAt, $totalWait);
            if ($outcome instanceof Response) {
                $outcome->attempts = $attempts;

                return $outcome;
            }

            $error = $outcome;

            // Эскалацию решает политика упавшей (текущей) модели — той же, по которой шли её повторы:
            // повторы и переключение живут по одной политике. Так then=stop и stopOn конкретной модели
            // цепочки работают, а не игнорируются в пользу политики стартовой модели.
            if (!$policy->shouldFallback($error)) {
                break;
            }

            $next = $this->nextModel($chain, $attempted);
            if ($next === null || $switches >= $maxSwitches) {
                break;
            }

            // Общий бюджет ограничивает и переключения: иначе цепочка продолжала бы перебор уже
            // после того, как отведённое на вызов время вышло. Бюджет модели здесь ни при чём —
            // у следующей модели свой отсчёт.
            if ($this->waitExceeded($totalWait, $startedAt, 0.0)) {
                $this->logger->warning('LLM total wait budget exhausted, stopping model switches', [
                    'model'               => $model->key,
                    'maxTotalWaitSeconds' => $totalWait,
                ]);
                break;
            }

            $switches++;
            $this->logger->info('LLM switching to next model in fallback chain', [
                'from'     => $model->key,
                'to'       => $next->key,
                'category' => $error->category,
            ]);
            $model = $next;
        }

        $error = $error ?? new ErrorInfo(ErrorCategory::UNKNOWN, 'Модель не отвечала.');

        $this->logger->error('LLM all attempts exhausted', [
            'model'    => $error->modelKey,
            'category' => $error->category,
            'message'  => $error->message,
            'attempts' => count($attempts),
        ]);

        $response = Response::failed($error);
        $response->attempts = $attempts;

        return $response;
    }

    /**
     * Попытки одной моделью: успех возвращается как Response, исчерпанные повторы — как ErrorInfo.
     *
     * @param AttemptLog[] $attempts   журнал попыток, дополняется по ссылке
     * @param float        $startedAt  начало всего вызова — по нему считается общий бюджет
     * @param float|null   $totalWait  общий бюджет вызова, секунды
     * @return Response|ErrorInfo
     */
    private function runModel(
        Request         $request,
        ModelDefinition $model,
        ErrorPolicy     $policy,
        array           &$attempts,
        float           $startedAt,
        ?float          $totalWait
    ) {
        $attempt = 0;
        $delayBefore = 0.0;
        $error = null;
        // Бюджет модели считается с её первой попытки: после переключения отсчёт начинается заново.
        $modelStartedAt = microtime(true);

        while (true) {
            $attempt++;
            $attemptStartedAt = microtime(true);

            try {
                $call = ResolvedCall::build(
                    $request,
                    $model,
                    $this->registry->providerOf($model),
                    $this->registry->defaultParams(),
                    $this->timeoutCap($totalWait, $startedAt)
                );
                $response = $this->provider($model)->execute($call);

                $log = new AttemptLog(
                    $model->key,
                    $model->provider,
                    $attempt,
                    true,
                    null,
                    microtime(true) - $attemptStartedAt,
                    $delayBefore
                );
                $log->maxAttempts = $policy->maxAttemptsFor(null);
                $attempts[] = $log;
                $this->notify($log);

                return $response;
            } catch (LlmException $e) {
                $error = $e->info()->withContext($model->provider, $model->key);
            } catch (Throwable $e) {
                $error = ErrorMapper::fromThrowable($e)->withContext($model->provider, $model->key);
            }

            $log = new AttemptLog(
                $model->key,
                $model->provider,
                $attempt,
                false,
                $error,
                microtime(true) - $attemptStartedAt,
                $delayBefore
            );
            $log->maxAttempts = $policy->maxAttemptsFor($error->category);
            $attempts[] = $log;

            $this->logger->warning('LLM attempt failed', [
                'model'    => $model->key,
                'provider' => $model->provider,
                'attempt'  => $attempt,
                'category' => $error->category,
                'message'  => $error->message,
            ]);

            // Решение о повторе принимается до уведомления наблюдателя: интерфейсу нужно знать
            // не только что попытка провалилась, но и будет ли следующая и через сколько.
            $retry = $policy->shouldRetry($error, $attempt);
            $delay = $retry ? $policy->delayFor($error->category, $attempt) : 0.0;

            // Два потолка: сколько эта модель уже занимает и сколько идёт весь вызов. Первый
            // отдаёт работу следующей модели, второй прекращает вызов целиком.
            if ($retry && $this->waitExceeded($policy->maxWaitSeconds, $modelStartedAt, $delay)) {
                $this->logger->warning('LLM model wait budget exhausted, stopping retries', [
                    'model'          => $model->key,
                    'maxWaitSeconds' => $policy->maxWaitSeconds,
                ]);
                $retry = false;
                $delay = 0.0;
            }

            if ($retry && $this->waitExceeded($totalWait, $startedAt, $delay)) {
                $this->logger->warning('LLM total wait budget exhausted, stopping retries', [
                    'model'               => $model->key,
                    'maxTotalWaitSeconds' => $totalWait,
                ]);
                $retry = false;
                $delay = 0.0;
            }

            $log->willRetry = $retry;
            $log->nextDelay = $delay;
            $this->notify($log);

            if (!$retry) {
                return $error;
            }

            $this->sleeper->sleep($delay);
            $delayBefore = $delay;
        }
    }

    /**
     * Следующая неопробованная модель цепочки.
     *
     * @param string[]              $chain
     * @param array<string, bool>   $attempted
     */
    private function nextModel(array $chain, array $attempted): ?ModelDefinition
    {
        foreach ($chain as $key) {
            $key = (string)$key;
            if (isset($attempted[$key])) {
                continue;
            }

            $model = $this->registry->findModel($key);
            if ($model !== null) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Сколько секунд осталось у вызова — этим ограничивается таймаут следующего запроса, чтобы он
     * не пережил бюджет. null — бюджета нет, таймаут берётся из каталога как есть.
     */
    private function timeoutCap(?float $totalWait, float $startedAt): ?int
    {
        if ($totalWait === null) {
            return null;
        }

        return (int)ceil(max(1.0, $totalWait - (microtime(true) - $startedAt)));
    }

    /**
     * Выйдет ли пауза за отведённый бюджет времени, отсчитываемый от $since.
     *
     * @param float|null $budget потолок в секундах; null — без потолка
     */
    private function waitExceeded(?float $budget, float $since, float $delay): bool
    {
        if ($budget === null) {
            return false;
        }

        return (microtime(true) - $since) + $delay > $budget;
    }

    /**
     * Провайдер модели. Инстансы кешируются: один транспорт обслуживает все свои модели, и кеш
     * переживает копирование исполнителя.
     */
    private function provider(ModelDefinition $model): ProviderInterface
    {
        $key = $model->provider;
        $definition = $this->registry->provider($key);

        // Сверяем и само определение: addProvider() мог заменить запись каталога (ротация токена,
        // переезд шлюза), а кешированный транспорт помнит прежние значения.
        if ($this->providers->offsetExists($key)) {
            [$cached, $provider] = $this->providers->offsetGet($key);
            if ($cached === $definition) {
                return $provider;
            }
        }

        $class = $definition->class;

        $provider = new $class($definition, $this->logger);
        $this->providers->offsetSet($key, [$definition, $provider]);

        return $provider;
    }

    /**
     * Сообщить наблюдателю о попытке. Наблюдатель — вспомогательный канал (прогресс в интерфейсе,
     * запись в базу), поэтому его сбой не должен превращать удачный ответ в ошибку и не должен
     * пробиваться наружу вопреки контракту «ничего не бросаем».
     */
    private function notify(AttemptLog $attempt): void
    {
        if ($this->observer === null) {
            return;
        }

        try {
            ($this->observer)($attempt);
        } catch (Throwable $e) {
            $this->logger->warning('LLM attempt observer failed', [
                'model'   => $attempt->modelKey,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
