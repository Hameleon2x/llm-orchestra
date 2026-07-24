<?php

namespace Hameleon2x\Llm\Config;

use Hameleon2x\Llm\Exception\LlmConfigException;
use Hameleon2x\Llm\Http\ChatClientInterface;
use Hameleon2x\Llm\Provider\ProviderInterface;

/**
 * Провайдер как транспорт: куда стучаться, чем авторизоваться, сколько ждать. Про модели,
 * температуру и политику ошибок он не знает — это уровень ModelDefinition.
 *
 * Один экземпляр обслуживает все модели, привязанные к этому ключу.
 */
final class ProviderDefinition
{
    /** Ключ в каталоге — им модель ссылается на провайдера. */
    public string $key;

    /** Класс провайдера (реализация ProviderInterface). */
    public string $class;

    /** Токен авторизации. Пустой допустим — локальные шлюзы его не требуют. */
    public string $token = '';

    /** Базовый URL без пути к эндпоинту. null — дефолт класса провайдера. */
    public ?string $baseUrl = null;

    /** Таймаут запроса, секунды. Модель может перекрыть своим. */
    public int $timeout = 120;

    /** Заголовки, добавляемые к каждому запросу провайдера. */
    public array $headers = [];

    /** Поля payload, добавляемые к каждому запросу провайдера. */
    public array $extraParams = [];

    /** Карта извлечения полей ответа: наше имя => путь (или список путей) в сыром ответе. */
    public array $capture = [];

    /** Писать в PSR-3 (уровень debug) исходящий payload и сырой ответ. */
    public bool $debug = false;

    /** Держать сырой ответ в Response. Отключается, если ответы большие и не нужны. */
    public bool $keepRaw = true;

    /**
     * Политика ошибок для моделей этого провайдера целиком (секция `policy`). Действует, когда
     * модель не задала свою; null — берётся политика каталога (`defaultPolicy`).
     */
    public ?ErrorPolicy $policy = null;

    /** Готовый HTTP-клиент или фабрика function(ProviderDefinition): ChatClientInterface. */
    public $httpClient = null;

    /** Произвольные данные приложения. */
    public array $meta = [];

    public static function fromArray(string $key, array $config): self
    {
        $definition = new self();
        $definition->key = $key;

        $class = (string)($config['class'] ?? '');
        if ($class === '') {
            throw new LlmConfigException("Провайдер «{$key}»: не указан class.");
        }
        if (!class_exists($class)) {
            throw new LlmConfigException("Провайдер «{$key}»: класс {$class} не найден.");
        }
        if (!is_a($class, ProviderInterface::class, true)) {
            throw new LlmConfigException(
                "Провайдер «{$key}»: класс {$class} не реализует " . ProviderInterface::class . '.'
            );
        }
        $definition->class = $class;

        $definition->token = (string)($config['token'] ?? '');
        $definition->baseUrl = isset($config['baseUrl']) && $config['baseUrl'] !== ''
            ? (string)$config['baseUrl']
            : null;
        $definition->timeout = isset($config['timeout']) ? (int)$config['timeout'] : 120;
        $definition->headers = (array)($config['headers'] ?? []);
        $definition->extraParams = (array)($config['extraParams'] ?? []);
        $definition->capture = (array)($config['capture'] ?? []);
        $definition->debug = (bool)($config['debug'] ?? false);
        $definition->keepRaw = (bool)($config['keepRaw'] ?? true);
        $definition->meta = (array)($config['meta'] ?? []);

        if (isset($config['policy']) && is_array($config['policy'])) {
            $definition->policy = ErrorPolicy::fromArray($config['policy']);
        }

        $httpClient = $config['httpClient'] ?? null;
        if ($httpClient !== null && !($httpClient instanceof ChatClientInterface) && !is_callable($httpClient)) {
            throw new LlmConfigException(
                "Провайдер «{$key}»: httpClient должен быть ChatClientInterface или фабрикой."
            );
        }
        $definition->httpClient = $httpClient;

        return $definition;
    }
}
