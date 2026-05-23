<?php

namespace Hameleon2x\Llm\Provider;

use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Enum\Status;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Exception\LlmValidationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Базовый провайдер LLM с retry-логикой и проверкой поддерживаемых моделей.
 */
abstract class BaseProvider implements ProviderInterface
{
    public string  $token;
    public string  $model;
    public ?string $baseUrl;
    public ?float  $temperature   = null;
    public ?float  $topP          = null;
    public ?int    $maxTokens     = null;
    public int     $retryAttempts = 3;
    public int     $timeout       = 30;
    public int     $priority      = 999;

    /**
     * Список поддерживаемых моделей (подстроки). null — поддерживаются все.
     * Пример: ['gpt-4', 'gpt-3.5', 'gpt-4o']
     */
    public ?array $supportedModels = null;

    protected LoggerInterface $logger;

    /**
     * @param string|null $baseUrl URL API (используется в OpenAiProvider и наследниках)
     * @param LoggerInterface|null $logger Логгер PSR-3; null — без логирования (NullLogger)
     */
    public function __construct(
        string           $token,
        string           $model,
        ?string          $baseUrl = null,
        ?float           $temperature = null,
        ?float           $topP = null,
        ?int             $maxTokens = null,
        int              $retryAttempts = 3,
        int              $timeout = 30,
        int              $priority = 999,
        ?array           $supportedModels = null,
        ?LoggerInterface $logger = null
    )
    {
        $this->token = $token;
        $this->model = $model;
        $this->baseUrl = $baseUrl;
        $this->temperature = $temperature;
        $this->topP = $topP;
        $this->maxTokens = $maxTokens;
        $this->retryAttempts = $retryAttempts;
        $this->timeout = $timeout;
        $this->priority = $priority;
        $this->supportedModels = $supportedModels;
        $this->logger = $logger ?? new NullLogger();
    }

    public function execute(Request $request): Response
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->retryAttempts) {
            $attempt++;
            $startTime = microtime(true);

            try {
                $response = $this->doExecute($request);

                $latency = microtime(true) - $startTime;
                $response->metadata['latency'] = $latency;
                $response->metadata['attempt'] = $attempt;

                return $response;

            } catch (LlmException $e) {
                $lastException = $e;

                $this->logger->warning('LLM provider attempt failed', [
                    'provider'  => $this->getName(),
                    'attempt'   => $attempt,
                    'error'     => $e->getMessage(),
                    'code'      => $e->getCode(),
                    'retryable' => $e->isRetryable(),
                ]);

                // Если ошибка не retryable — сразу выходим
                if (!$e->isRetryable()) {
                    break;
                }

                // Если это не последняя попытка — ждём перед повтором
                if ($attempt < $this->retryAttempts) {
                    $this->sleep($attempt);
                }
            }
        }

        $latency = microtime(true) - $startTime;

        return Response::error(
            $this->getStatusFromException($lastException),
            $this->getName(),
            $request->model ?? $this->model,
            $lastException !== null ? $lastException->getMessage() : 'Unknown error',
            $lastException,
            [
                'latency'  => $latency,
                'attempts' => $attempt,
            ]
        );
    }

    /**
     * Выполнить запрос к провайдеру без retry-логики.
     *
     * @throws LlmException
     */
    abstract protected function doExecute(Request $request): Response;

    public function getName(): string
    {
        return static::class;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    protected function getTemperature(Request $request, float $default = 0.7): float
    {
        return $request->temperature ?? $this->temperature ?? $default;
    }

    protected function getTopP(Request $request, float $default = 0.95): float
    {
        return $request->topP ?? $this->topP ?? $default;
    }

    protected function getMaxTokens(Request $request, int $default = 1024): int
    {
        return $request->maxTokens ?? $this->maxTokens ?? $default;
    }

    /**
     * Имя модели для запроса. Если в запросе явно указана модель и провайдер её не поддерживает —
     * бросает исключение, чтобы Client перешёл к следующему провайдеру.
     *
     * @throws LlmValidationException Если модель не поддерживается провайдером
     */
    protected function getModel(Request $request): string
    {
        $requestedModel = $request->model ?? $this->model;

        if ($request->model !== null && !$this->isModelSupported($requestedModel)) {
            throw new LlmValidationException(
                "Model '{$requestedModel}' is not supported by {$this->getName()} provider"
            );
        }

        return $requestedModel;
    }

    protected function isModelSupported(string $model): bool
    {
        if ($this->supportedModels === null) {
            return true;
        }

        foreach ($this->supportedModels as $pattern) {
            if (strpos($model, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    protected function getStatusFromException(?Throwable $e): string
    {
        if ($e === null) {
            return Status::ERROR;
        }

        $class = get_class($e);

        if (strpos($class, 'RateLimit') !== false) {
            return Status::RATE_LIMIT;
        }
        if (strpos($class, 'Validation') !== false) {
            return Status::VALIDATION_ERROR;
        }
        if (strpos($class, 'Provider') !== false) {
            return Status::PROVIDER_ERROR;
        }
        if (strpos($class, 'Timeout') !== false) {
            return Status::TIMEOUT;
        }

        return Status::ERROR;
    }

    /**
     * Exponential backoff: 1s → 2s → 4s → 8s, потолок 10с.
     */
    protected function sleep(int $attempt): void
    {
        $seconds = min(pow(2, $attempt - 1), 10);
        usleep($seconds * 1_000_000);
    }
}
