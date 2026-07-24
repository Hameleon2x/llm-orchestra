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

    /** @var array<string, ProviderInterface> инстансы провайдеров по ключу каталога */
    private array $providers = [];

    /** Политика, перекрывающая политику каталога и моделей. */
    private ?ErrorPolicy $policyOverride = null;

    /** @var string[]|null цепочка, перекрывающая цепочку каталога */
    private ?array $fallbackOverride = null;

    private ?int $maxSwitchesOverride = null;

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
     * @param string|null $modelKey ключ или алиас модели каталога; null — модель каталога по умолчанию
     */
    public function execute(Request $request, ?string $modelKey = null): Response
    {
        try {
            $model = $this->registry->model($this->registry->normalize($modelKey));
        } catch (LlmConfigException $e) {
            return Response::failed($e->info());
        }

        $rootPolicy = $this->policyOverride ?? $this->registry->policyFor($model);
        $chain = $this->fallbackOverride ?? $this->registry->fallbackChain();
        $maxSwitches = $this->maxSwitchesOverride ?? $this->registry->maxSwitches();

        $attempts = [];
        $attempted = [];
        $switches = 0;
        $startedAt = microtime(true);
        $error = null;

        while (true) {
            $attempted[$model->key] = true;
            $policy = $this->policyOverride ?? $this->registry->policyFor($model);

            $outcome = $this->runModel($request, $model, $policy, $attempts, $startedAt);
            if ($outcome instanceof Response) {
                $outcome->attempts = $attempts;
                $outcome->metadata['attempts'] = count($attempts);

                return $outcome;
            }

            $error = $outcome;

            if (!$rootPolicy->shouldFallback($error)) {
                break;
            }

            $next = $this->nextModel($chain, $attempted);
            if ($next === null || $switches >= $maxSwitches) {
                break;
            }

            // Бюджет ожидания ограничивает и переключения: иначе цепочка продолжала бы перебор
            // уже после того, как отведённое на вызов время вышло.
            if ($this->budgetExceeded($rootPolicy, $startedAt, 0.0)) {
                $this->logger->warning('LLM wait budget exhausted, stopping model switches', [
                    'model'          => $model->key,
                    'maxWaitSeconds' => $rootPolicy->maxWaitSeconds,
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
        $response->metadata['attempts'] = count($attempts);

        return $response;
    }

    /**
     * Попытки одной моделью: успех возвращается как Response, исчерпанные повторы — как ErrorInfo.
     *
     * @param AttemptLog[] $attempts журнал попыток, дополняется по ссылке
     * @return Response|ErrorInfo
     */
    private function runModel(
        Request         $request,
        ModelDefinition $model,
        ErrorPolicy     $policy,
        array           &$attempts,
        float           $startedAt
    ) {
        $attempt = 0;
        $delayBefore = 0.0;
        $error = null;

        while (true) {
            $attempt++;
            $attemptStartedAt = microtime(true);

            try {
                $call = ResolvedCall::build(
                    $request,
                    $model,
                    $this->registry->providerOf($model),
                    $this->registry->defaultParams()
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

            if ($retry && $this->budgetExceeded($policy, $startedAt, $delay)) {
                $this->logger->warning('LLM wait budget exhausted, stopping retries', [
                    'model'          => $model->key,
                    'maxWaitSeconds' => $policy->maxWaitSeconds,
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
            if (isset($attempted[$key])) {
                continue;
            }
            $model = $this->registry->findModel((string)$key);
            if ($model !== null && !isset($attempted[$model->key])) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Выйдет ли пауза за отведённый на вызов бюджет времени.
     */
    private function budgetExceeded(ErrorPolicy $policy, float $startedAt, float $delay): bool
    {
        if ($policy->maxWaitSeconds === null) {
            return false;
        }

        return (microtime(true) - $startedAt) + $delay > $policy->maxWaitSeconds;
    }

    /**
     * Провайдер модели. Инстансы кешируются: один транспорт обслуживает все свои модели.
     */
    private function provider(ModelDefinition $model): ProviderInterface
    {
        $key = $model->provider;
        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        $definition = $this->registry->provider($key);
        $class = $definition->class;

        return $this->providers[$key] = new $class($definition, $this->logger);
    }

    private function notify(AttemptLog $attempt): void
    {
        if ($this->observer === null) {
            return;
        }

        ($this->observer)($attempt);
    }
}
