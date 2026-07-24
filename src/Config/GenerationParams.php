<?php

namespace Hameleon2x\Llm\Config;

use Hameleon2x\Llm\Support\ConfigKeys;

/**
 * Параметры генерации: то, что понимает любой провайдер и что имеет смысл задавать на каждом
 * уровне — в каталоге по умолчанию, у модели, у конкретного вызова.
 *
 * Все поля nullable: null означает «не задано на этом уровне», а не «ноль». Слияние идёт по
 * явности — заданное ближе к вызову перекрывает заданное в конфиге.
 */
final class GenerationParams
{
    public ?float $temperature = null;
    public ?float $topP = null;
    public ?int   $maxTokens = null;
    public ?int   $seed = null;

    /** Соответствие имён конфига именам полей OpenAI-совместимого payload. */
    private const PAYLOAD_KEYS = [
        'temperature' => 'temperature',
        'topP'        => 'top_p',
        'maxTokens'   => 'max_tokens',
        'seed'        => 'seed',
    ];

    /** Написания, которые допускаем в списке unsupported, чтобы не спотыкаться на snake_case. */
    private const ALIASES = [
        'top_p'      => 'topP',
        'max_tokens' => 'maxTokens',
    ];

    public static function fromArray(array $config): self
    {
        ConfigKeys::assertKnown($config, ['temperature', 'topP', 'maxTokens', 'seed'], 'Параметры генерации');

        $params = new self();
        $params->temperature = isset($config['temperature']) ? (float)$config['temperature'] : null;
        $params->topP = isset($config['topP']) ? (float)$config['topP'] : null;
        $params->maxTokens = isset($config['maxTokens']) ? (int)$config['maxTokens'] : null;
        $params->seed = isset($config['seed']) ? (int)$config['seed'] : null;

        return $params;
    }

    /**
     * Имена, которые можно перечислять в `unsupported`: имена полей плюс их написание в payload.
     *
     * @return string[]
     */
    public static function knownNames(): array
    {
        return array_merge(array_keys(self::PAYLOAD_KEYS), array_keys(self::ALIASES));
    }

    /**
     * Есть ли такой параметр генерации (в любом из допустимых написаний).
     */
    public static function isKnownName(string $name): bool
    {
        return isset(self::PAYLOAD_KEYS[self::ALIASES[$name] ?? $name]);
    }

    /**
     * Копия, в которой заданные поля $override перекрывают текущие.
     */
    public function merge(?self $override): self
    {
        if ($override === null) {
            return clone $this;
        }

        $merged = clone $this;
        foreach (array_keys(self::PAYLOAD_KEYS) as $field) {
            if ($override->{$field} !== null) {
                $merged->{$field} = $override->{$field};
            }
        }

        return $merged;
    }

    /**
     * Поля для payload: незаданные и перечисленные в $unsupported пропускаются.
     *
     * $unsupported — способ сказать «эта модель такой параметр не принимает» (reasoning-модели
     * отвергают temperature). Он сильнее любого уровня конфигурации, потому что описывает не
     * пожелание, а ограничение модели.
     *
     * @param string[] $unsupported
     */
    public function toPayload(array $unsupported = []): array
    {
        $skip = [];
        foreach ($unsupported as $name) {
            $name = (string)$name;
            $skip[self::ALIASES[$name] ?? $name] = true;
        }

        $payload = [];
        foreach (self::PAYLOAD_KEYS as $field => $payloadKey) {
            if (isset($skip[$field]) || $this->{$field} === null) {
                continue;
            }
            $payload[$payloadKey] = $this->{$field};
        }

        return $payload;
    }
}
