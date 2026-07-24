<?php

namespace Hameleon2x\Llm;

use Hameleon2x\Llm\Config\ErrorPolicy;
use Hameleon2x\Llm\Config\GenerationParams;
use Hameleon2x\Llm\Config\ModelDefinition;
use Hameleon2x\Llm\Config\ProviderDefinition;
use Hameleon2x\Llm\Exception\LlmConfigException;

/**
 * Каталог: провайдеры-транспорты, модели, дефолты генерации, политика ошибок и цепочка фолбэка.
 *
 * Собирается из массива конфигурации приложения и проверяется целиком при сборке — опечатка в
 * ключе цепочки или ссылка на несуществующий провайдер обнаруживается сразу, а не в момент сбоя.
 * Каталог, который хранится в базе, тоже собирается через fromArray: соберите массив и передайте
 * его целиком. addProvider/addModel дописывают отдельные записи в уже собранный каталог.
 *
 * ```php
 * $registry = Registry::fromArray([
 *     'providers' => ['requesty' => ['class' => RequestyProvider::class, 'token' => '...']],
 *     'models'    => ['glm-4.6'  => ['provider' => 'requesty', 'name' => 'zai/GLM-4.6']],
 *     'defaultModel' => 'glm-4.6',
 * ]);
 * ```
 */
final class Registry
{
    /** @var array<string, ProviderDefinition> */
    private array $providers = [];

    /** @var array<string, ModelDefinition> */
    private array $models = [];

    private GenerationParams $defaultParams;

    private ErrorPolicy $defaultPolicy;

    /** @var string[] порядок эскалации при сбое: ключи моделей */
    private array $fallback = [];

    /** Сколько раз за один вызов разрешено переключиться на следующую модель цепочки. */
    private int $maxSwitches = 2;

    /**
     * Потолок времени на весь вызов, секунды: все модели, все их повторы и паузы. Ограничитель
     * прогона, а не модели, поэтому живёт рядом с цепочкой и maxSwitches, а не в политике ошибок.
     * null — без потолка.
     */
    private ?float $maxTotalWaitSeconds = null;

    private ?string $defaultModel = null;

    public function __construct()
    {
        $this->defaultParams = new GenerationParams();
        $this->defaultPolicy = new ErrorPolicy();
    }

    /**
     * Собрать каталог из конфигурации приложения.
     *
     * Ключи: providers, models, defaultModel, defaultParams, defaultPolicy, fallback, maxSwitches,
     * maxTotalWaitSeconds.
     */
    public static function fromArray(array $config): self
    {
        $registry = new self();

        if (isset($config['defaultParams']) && is_array($config['defaultParams'])) {
            $registry->defaultParams = GenerationParams::fromArray($config['defaultParams']);
        }
        if (isset($config['defaultPolicy']) && is_array($config['defaultPolicy'])) {
            $registry->defaultPolicy = ErrorPolicy::fromArray($config['defaultPolicy']);
        }

        foreach ((array)($config['providers'] ?? []) as $key => $providerConfig) {
            $registry->addProvider(ProviderDefinition::fromArray((string)$key, (array)$providerConfig));
        }

        foreach ((array)($config['models'] ?? []) as $key => $modelConfig) {
            $registry->addModel(ModelDefinition::fromArray((string)$key, (array)$modelConfig));
        }

        $registry->fallback = array_values((array)($config['fallback'] ?? []));
        if (isset($config['maxSwitches'])) {
            $registry->maxSwitches = max(0, (int)$config['maxSwitches']);
        }
        if (array_key_exists('maxTotalWaitSeconds', $config)) {
            $registry->maxTotalWaitSeconds = $config['maxTotalWaitSeconds'] !== null
                ? (float)$config['maxTotalWaitSeconds']
                : null;
        }
        if (isset($config['defaultModel']) && $config['defaultModel'] !== '') {
            $registry->defaultModel = (string)$config['defaultModel'];
        }

        $registry->validate();

        return $registry;
    }

    public function addProvider(ProviderDefinition $provider): self
    {
        $this->providers[$provider->key] = $provider;

        return $this;
    }

    public function addModel(ModelDefinition $model): self
    {
        $this->models[$model->key] = $model;

        return $this;
    }

    /**
     * Проверить целостность каталога.
     *
     * @throws LlmConfigException
     */
    public function validate(): void
    {
        if ($this->providers === []) {
            throw new LlmConfigException('Каталог LLM: не задан ни один провайдер.');
        }
        if ($this->models === []) {
            throw new LlmConfigException('Каталог LLM: не задана ни одна модель.');
        }

        foreach ($this->models as $model) {
            if (!isset($this->providers[$model->provider])) {
                throw new LlmConfigException(
                    "Модель «{$model->key}»: провайдер «{$model->provider}» не найден в каталоге."
                );
            }
        }

        foreach ($this->fallback as $modelKey) {
            if ($this->findModel((string)$modelKey) === null) {
                throw new LlmConfigException("Цепочка фолбэка: модель «{$modelKey}» не найдена в каталоге.");
            }
        }

        if ($this->defaultModel !== null && $this->findModel($this->defaultModel) === null) {
            throw new LlmConfigException("Модель по умолчанию «{$this->defaultModel}» не найдена в каталоге.");
        }
    }

    /**
     * Модель по ключу каталога.
     *
     * @throws LlmConfigException если модели нет
     */
    public function model(string $key): ModelDefinition
    {
        $model = $this->findModel($key);
        if ($model === null) {
            throw new LlmConfigException("Модель «{$key}» не найдена в каталоге.");
        }

        return $model;
    }

    /**
     * Модель по ключу каталога; null, если такой нет.
     */
    public function findModel(?string $key): ?ModelDefinition
    {
        if ($key === null || $key === '') {
            return null;
        }

        return $this->models[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return $this->findModel($key) !== null;
    }

    /**
     * Привести значение к ключу каталога: неизвестное или пустое заменяется на $default, а если он
     * не задан — на модель каталога по умолчанию.
     *
     * Слово «fallback» здесь намеренно не используется: цепочка фолбэка — про эскалацию при сбое,
     * а тут речь о подстановке вместо неизвестного значения.
     *
     * @throws LlmConfigException если подставить нечего
     */
    public function normalize(?string $key, ?string $default = null): string
    {
        $model = $this->findModel($key);
        if ($model !== null) {
            return $model->key;
        }

        $model = $this->findModel($default) ?? $this->findModel($this->defaultModel);
        if ($model === null) {
            throw new LlmConfigException(
                'Не удалось выбрать модель: значение неизвестно, а модель по умолчанию в каталоге не задана.'
            );
        }

        return $model->key;
    }

    /**
     * Провайдер по ключу.
     *
     * @throws LlmConfigException
     */
    public function provider(string $key): ProviderDefinition
    {
        if (!isset($this->providers[$key])) {
            throw new LlmConfigException("Провайдер «{$key}» не найден в каталоге.");
        }

        return $this->providers[$key];
    }

    /**
     * Провайдер, через которого работает модель.
     */
    public function providerOf(ModelDefinition $model): ProviderDefinition
    {
        return $this->provider($model->provider);
    }

    /**
     * @return array<string, ProviderDefinition>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array<string, ModelDefinition>
     */
    public function all(): array
    {
        return $this->models;
    }

    /**
     * Модели одного провайдера.
     *
     * @return array<string, ModelDefinition>
     */
    public function byProvider(string $providerKey): array
    {
        return array_filter(
            $this->models,
            static fn(ModelDefinition $model): bool => $model->provider === $providerKey
        );
    }

    /**
     * Модели с меткой.
     *
     * @return array<string, ModelDefinition>
     */
    public function byTag(string $tag): array
    {
        return array_filter($this->models, static fn(ModelDefinition $model): bool => $model->hasTag($tag));
    }

    /**
     * Подписи моделей для списка выбора: ключ => человекочитаемое название.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        $labels = [];
        foreach ($this->models as $key => $model) {
            $labels[$key] = $model->label();
        }

        return $labels;
    }

    public function defaultModelKey(): ?string
    {
        return $this->defaultModel;
    }

    public function defaultParams(): GenerationParams
    {
        return $this->defaultParams;
    }

    public function defaultPolicy(): ErrorPolicy
    {
        return $this->defaultPolicy;
    }

    /**
     * Политика, по которой выполняется эта модель.
     *
     * Уровни не смешиваются: действует ближайшая заданная политика целиком — модели, затем её
     * провайдера, затем каталога. Так по конфигу сразу видно, чем именно управляется вызов.
     */
    public function policyFor(ModelDefinition $model): ErrorPolicy
    {
        if ($model->policy !== null) {
            return $model->policy;
        }

        $provider = $this->providers[$model->provider] ?? null;
        if ($provider !== null && $provider->policy !== null) {
            return $provider->policy;
        }

        return $this->defaultPolicy;
    }

    /**
     * Порядок эскалации при сбое.
     *
     * @return string[]
     */
    public function fallbackChain(): array
    {
        return $this->fallback;
    }

    public function maxSwitches(): int
    {
        return $this->maxSwitches;
    }

    /**
     * Потолок времени на весь вызов, секунды. null — без потолка.
     */
    public function maxTotalWaitSeconds(): ?float
    {
        return $this->maxTotalWaitSeconds;
    }

    /**
     * Оценка стоимости по ценам каталога, доллары. null — цены для модели не заданы.
     *
     * Это именно оценка: если провайдер вернул фактическую стоимость, она точнее (см. Usage::$cost).
     */
    public function costOf(string $modelKey, int $promptTokens, int $completionTokens): ?float
    {
        $model = $this->findModel($modelKey);
        if ($model === null || $model->pricing === null) {
            return null;
        }

        return ($promptTokens * $model->pricing['in'] + $completionTokens * $model->pricing['out']) / 1_000_000;
    }
}
