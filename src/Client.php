<?php

namespace Hameleon2x\Llm;

use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Enum\Status;
use Hameleon2x\Llm\Exception\LlmException;
use Hameleon2x\Llm\Provider\ProviderInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/**
 * Клиент LLM: выбор провайдера по приоритету, fallback при ошибках.
 *
 * Использование:
 *
 * 1. Простой запрос:
 * ```php
 * use Hameleon2x\Llm\Client;
 * use Hameleon2x\Llm\Dto\Request;
 *
 * $client = new Client();
 * $client->providers = [
 *     ['class' => OpenAiProvider::class, 'token' => '...', 'model' => 'gpt-4o-mini'],
 * ];
 *
 * $request = Request::simple('You are helpful assistant', 'What is PHP?');
 * $response = $client->execute($request);
 * if ($response->isSuccess()) {
 *     echo $response->content;
 * }
 * ```
 *
 * 2. Запрос с инструментами:
 * ```php
 * $tools = [
 *     ToolDefinition::function('get_weather', 'Get current weather', [...]),
 * ];
 * $request = Request::withTools($messages, $tools, 'auto');
 * $response = $client->execute($request);
 * ```
 *
 * 3. С логгером (PSR-3):
 * ```php
 * $client = new Client($logger);
 * ```
 */
class Client
{
    /**
     * @var ProviderInterface[] Массив провайдеров
     */
    public array $providers = [];

    /**
     * @var float Температура по умолчанию
     */
    public float $defaultTemperature = 0.7;

    /**
     * @var float|null TopP по умолчанию. null — top_p не отправляется, если не задан явно (в конфиге
     * провайдера, Request или Config): часть провайдеров (напр. Anthropic) не принимает temperature и
     * top_p одновременно. Управление семплированием по умолчанию — через temperature.
     */
    public ?float $defaultTopP = null;

    /**
     * @var int Максимальное количество токенов по умолчанию
     */
    public int $defaultMaxTokens = 1024;

    /**
     * @var ProviderInterface[]|null Отсортированные провайдеры
     */
    private ?array $sortedProviders = null;

    /**
     * @var bool Флаг инициализации провайдеров
     */
    private bool $initialized = false;

    private LoggerInterface $logger;

    /**
     * @param LoggerInterface|null $logger Логгер PSR-3; null — без логирования (NullLogger).
     *                                     Тот же логгер автоматически пробрасывается во все провайдеры,
     *                                     создаваемые из массива конфигурации.
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Инициализация провайдеров (ленивая)
     */
    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        if (empty($this->providers)) {
            throw new RuntimeException('No LLM providers configured');
        }

        // Инициализируем провайдеры если переданы как массивы конфигурации
        foreach ($this->providers as $i => $provider) {
            if (is_array($provider)) {
                $this->providers[$i] = $this->createProvider($provider);
            }
        }

        $this->initialized = true;
    }

    /**
     * Создать объект провайдера из массива конфигурации
     */
    private function createProvider(array $config): ProviderInterface
    {
        if (!isset($config['class'])) {
            throw new RuntimeException('Provider class is not specified');
        }

        if (!isset($config['token']) || empty($config['token'])) {
            throw new RuntimeException("Provider token is required for {$config['class']}");
        }

        if (!isset($config['model']) || empty($config['model'])) {
            throw new RuntimeException("Provider model is required for {$config['class']}");
        }

        $class = $config['class'];

        // Создаём объект с параметрами конструктора. Дефолтные значения берутся из свойств Client.
        // baseUrl передаётся для OpenAI-совместимых провайдеров (OpenAi, OpenRouter, Requesty).
        // Логгер пробрасывается из клиента — провайдер пишет retry-попытки в тот же канал.
        return new $class(
            $config['token'],
            $config['model'],
            $config['baseUrl'] ?? null,
            $config['temperature'] ?? $this->defaultTemperature,
            $config['topP'] ?? $this->defaultTopP,
            $config['maxTokens'] ?? $this->defaultMaxTokens,
            $config['retryAttempts'] ?? 3,
            $config['timeout'] ?? 30,
            $config['priority'] ?? 999,
            $config['supportedModels'] ?? null,
            $this->logger
        );
    }

    /**
     * Выполнить запрос к LLM
     * Пробует провайдеры по порядку приоритета
     */
    public function execute(Request $request): Response
    {
        $this->ensureInitialized();

        $providers = $this->getSortedProviders();

        if (empty($providers)) {
            return Response::error(
                Status::ERROR,
                'none',
                'none',
                'No providers available'
            );
        }

        $lastResponse = null;
        $attemptedProviders = [];

        foreach ($providers as $provider) {
            try {
                $attemptedProviders[] = $provider->getName();

                $response = $provider->execute($request);

                // Если успешно - возвращаем результат
                if ($response->isSuccess()) {
                    return $response;
                }

                // Если не успешно - сохраняем ответ и пробуем следующий
                $lastResponse = $response;
                $this->logger->warning('LLM provider returned unsuccessful response', [
                    'provider' => $provider->getName(),
                    'status'   => $response->status,
                    'error'    => $response->error,
                ]);

            } catch (LlmException $e) {
                $this->logger->warning('LLM provider threw exception during request', [
                    'provider'  => $provider->getName(),
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                ]);

                continue;
            } catch (Throwable $e) {
                $this->logger->error('Unexpected exception while calling LLM provider', [
                    'provider'  => $provider->getName(),
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);

                continue;
            }
        }

        // Все провайдеры не сработали
        $this->logger->error('All LLM providers failed', [
            'providers_attempted' => $attemptedProviders,
            'last_status'         => $lastResponse ? $lastResponse->status : null,
            'last_error'          => $lastResponse ? $lastResponse->error : null,
        ]);

        return $lastResponse ?? Response::error(
            Status::ERROR,
            'all',
            'none',
            'All providers failed'
        );
    }

    /**
     * Получить провайдеры отсортированные по приоритету
     *
     * @return ProviderInterface[]
     */
    protected function getSortedProviders(): array
    {
        if ($this->sortedProviders === null) {
            $this->sortedProviders = $this->providers;

            usort($this->sortedProviders, function (ProviderInterface $a, ProviderInterface $b) {
                return $a->getPriority() <=> $b->getPriority();
            });
        }

        return $this->sortedProviders;
    }

    /**
     * Добавить провайдер
     */
    public function addProvider(ProviderInterface $provider): void
    {
        $this->ensureInitialized();

        $this->providers[] = $provider;
        $this->sortedProviders = null; // Сброс кэша
    }

    /**
     * Получить все провайдеры
     *
     * @return ProviderInterface[]
     */
    public function getProviders(): array
    {
        $this->ensureInitialized();

        return $this->providers;
    }

    /**
     * Получить провайдер по имени
     */
    public function getProvider(string $name): ?ProviderInterface
    {
        $this->ensureInitialized();

        foreach ($this->providers as $provider) {
            if ($provider->getName() === $name) {
                return $provider;
            }
        }
        return null;
    }

    /**
     * Создать отдельный клиент со своим списком провайдеров.
     *
     * Исходный клиент остаётся клиентом по умолчанию. Метод нужен, когда части
     * приложения требуется работать через собственные провайдеры (другой токен,
     * модель или провайдер), не затрагивая общий клиент. Дефолты генерации
     * (temperature, topP, maxTokens) и логгер копируются из текущего клиента.
     *
     * @param array[] $providers конфигурация провайдеров в формате свойства $providers
     */
    public function withProviders(array $providers): self
    {
        if (empty($providers)) {
            throw new RuntimeException('Список провайдеров не может быть пустым');
        }

        $clone = new self($this->logger);
        $clone->providers = $providers;
        $clone->defaultTemperature = $this->defaultTemperature;
        $clone->defaultTopP = $this->defaultTopP;
        $clone->defaultMaxTokens = $this->defaultMaxTokens;

        return $clone;
    }
}
