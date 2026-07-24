<?php

namespace Hameleon2x\Llm\Config;

use Hameleon2x\Llm\Exception\LlmConfigException;

/**
 * Модель в каталоге — единица выбора, настройки и политики. Имён у неё ровно два: ключ каталога
 * (хранится в базе приложения и приходит из интерфейса) и слаг для API в `name`, который может
 * совпадать у двух записей, привязанных к разным провайдерам.
 *
 * Отсюда же берётся пресет режима: две записи с одним `name`, но разными `extraParams` —
 * это «GLM-4.6» и «GLM-4.6 с глубоким мышлением» в списке выбора.
 */
final class ModelDefinition
{
    /** Ключ каталога. */
    public string $key;

    /** Ключ провайдера, через которого зовём модель. */
    public string $provider;

    /** Слаг модели для API провайдера. */
    public string $name;

    /** Человекочитаемое название для интерфейса. */
    public string $fullName = '';

    /** Пояснение для интерфейса: чем модель хороша и когда её брать. */
    public string $description = '';

    /** Параметры генерации модели. */
    public GenerationParams $params;

    /**
     * Параметры, которые модель не принимает (`temperature`, `topP`, `maxTokens`, `seed`).
     * Вырезаются из payload независимо от того, кто и где их задал.
     *
     * @var string[]
     */
    public array $unsupported = [];

    /** Поля payload, специфичные для модели: режим размышлений, усилие рассуждения и т. п. */
    public array $extraParams = [];

    /** Заголовки, специфичные для модели. */
    public array $headers = [];

    /** Карта извлечения полей ответа поверх карты провайдера. */
    public array $capture = [];

    /**
     * Политика ошибок этой модели целиком (секция `policy`). Задана — действует она, политики
     * провайдера и каталога не подмешиваются. null — берётся политика провайдера, а если и её нет,
     * политика каталога (`defaultPolicy`).
     */
    public ?ErrorPolicy $policy = null;

    /** Таймаут запроса, секунды. null — таймаут провайдера. */
    public ?int $timeout = null;

    /**
     * Цена за миллион токенов: `['in' => 1.25, 'out' => 10.0]`. Необязательна и используется
     * только как оценка, когда провайдер не вернул фактическую стоимость.
     */
    public ?array $pricing = null;

    /**
     * Метки для группировки в приложении («быстрые», «с инструментами»).
     *
     * @var string[]
     */
    public array $tags = [];

    /** Произвольные данные приложения (вес ротации, иконка, что угодно). */
    public array $meta = [];

    public function __construct()
    {
        $this->params = new GenerationParams();
    }

    public static function fromArray(string $key, array $config): self
    {
        $definition = new self();
        $definition->key = $key;

        $provider = (string)($config['provider'] ?? '');
        if ($provider === '') {
            throw new LlmConfigException("Модель «{$key}»: не указан provider.");
        }
        $definition->provider = $provider;

        $name = (string)($config['name'] ?? '');
        if ($name === '') {
            throw new LlmConfigException("Модель «{$key}»: не указан name (слаг модели для API).");
        }
        $definition->name = $name;

        $definition->fullName = (string)($config['fullName'] ?? $key);
        $definition->description = (string)($config['description'] ?? '');
        $definition->params = GenerationParams::fromArray((array)($config['params'] ?? []));
        // Опечатка в unsupported ничего не вырезала бы, а провайдер ответил бы на такой запрос
        // ошибкой bad_request — её не повторяют и не пробуют другой моделью.
        foreach ((array)($config['unsupported'] ?? []) as $name) {
            if (!GenerationParams::isKnownName((string)$name)) {
                throw new LlmConfigException(
                    "Модель «{$key}»: неизвестный параметр в unsupported — «{$name}». Допустимы: "
                    . implode(', ', GenerationParams::knownNames()) . '.'
                );
            }
            $definition->unsupported[] = (string)$name;
        }
        $definition->extraParams = (array)($config['extraParams'] ?? []);
        $definition->headers = (array)($config['headers'] ?? []);
        $definition->capture = (array)($config['capture'] ?? []);
        $definition->timeout = isset($config['timeout']) ? max(1, (int)$config['timeout']) : null;
        $definition->tags = (array)($config['tags'] ?? []);
        $definition->meta = (array)($config['meta'] ?? []);

        if (isset($config['policy']) && is_array($config['policy'])) {
            $definition->policy = ErrorPolicy::fromArray($config['policy']);
        }

        if (isset($config['pricing']) && is_array($config['pricing'])) {
            $definition->pricing = [
                'in'  => (float)($config['pricing']['in'] ?? 0),
                'out' => (float)($config['pricing']['out'] ?? 0),
            ];
        }

        return $definition;
    }

    /**
     * Подпись для интерфейса и логов.
     */
    public function label(): string
    {
        return $this->fullName !== '' ? $this->fullName : $this->key;
    }

    /**
     * Метка есть у модели.
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
}
