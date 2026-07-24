<?php

namespace Hameleon2x\Llm\Provider;

use Hameleon2x\Llm\Config\ProviderDefinition;
use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Http\ChatClientInterface;
use Hameleon2x\Llm\Http\CurlChatClient;
use Hameleon2x\Llm\Support\ArrayPath;
use Hameleon2x\Llm\Support\Merge;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Общая часть провайдеров: транспорт, карта извлечения полей ответа и доступ к настройкам каталога.
 *
 * Повторов здесь нет: единственный уровень повторов — политика модели в Orchestra, поэтому время
 * ожидания при сбое остаётся предсказуемым.
 */
abstract class BaseProvider implements ProviderInterface
{
    protected ProviderDefinition $definition;

    protected LoggerInterface $logger;

    private ?ChatClientInterface $client = null;

    public function __construct(ProviderDefinition $definition, ?LoggerInterface $logger = null)
    {
        $this->definition = $definition;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Базовый URL API, если в каталоге он не задан.
     */
    abstract protected function defaultBaseUrl(): string;

    /**
     * Путь эндпоинта относительно базового URL. Формат API — знание провайдера, поэтому транспорт
     * получает уже собранный адрес и о путях ничего не знает.
     */
    abstract protected function endpointPath(): string;

    /**
     * Полный адрес, по которому провайдер зовёт модель.
     */
    protected function endpointUrl(): string
    {
        return rtrim($this->definition->baseUrl ?? $this->defaultBaseUrl(), '/') . $this->endpointPath();
    }

    /**
     * Карта извлечения полей ответа по умолчанию: наше имя => путь или список путей.
     *
     * Список путей нужен, когда одно и то же поле разные шлюзы называют по-разному — побеждает
     * первый непустой. Конфигурация провайдера и модели дополняет и перекрывает эту карту.
     */
    protected function defaultCapture(): array
    {
        return [];
    }

    /**
     * HTTP-клиент: из каталога (готовый объект или фабрика) либо cURL по умолчанию.
     */
    protected function client(): ChatClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $configured = $this->definition->httpClient;

        if ($configured instanceof ChatClientInterface) {
            return $this->client = $configured;
        }

        if (is_callable($configured)) {
            // Готовый адрес отдаём и своему клиенту: путь эндпоинта знает провайдер, и собирать
            // его вручную в каждой фабрике незачем.
            return $this->client = $configured($this->definition, $this->endpointUrl());
        }

        return $this->client = new CurlChatClient(
            $this->endpointUrl(),
            $this->definition->token,
            $this->definition->timeout,
            $this->definition->debug,
            $this->logger
        );
    }

    /**
     * Достать из сырого ответа поля по карте capture.
     *
     * @return array<string, mixed>
     */
    protected function capture(array $raw, ResolvedCall $call): array
    {
        $map = Merge::deep($this->defaultCapture(), $call->capture);

        $captured = [];
        foreach ($map as $name => $paths) {
            $value = is_array($paths)
                ? ArrayPath::first($raw, $paths)
                : ArrayPath::get($raw, (string)$paths);

            if ($value !== null && $value !== '' && $value !== []) {
                $captured[(string)$name] = $value;
            }
        }

        return $captured;
    }
}
