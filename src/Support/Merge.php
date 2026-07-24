<?php

namespace Hameleon2x\Llm\Support;

/**
 * Слияние произвольных полей конфигурации: провайдер → модель → вызов.
 *
 * Правила:
 *   - ассоциативные массивы сливаются рекурсивно (`reasoning.enabled` не затирает `reasoning.effort`);
 *   - списки заменяются целиком (`provider.order` — это выбор, а не накопление);
 *   - значение null удаляет ключ, чтобы модель могла отменить то, что задал её провайдер.
 */
final class Merge
{
    /**
     * Слить $override поверх $base по правилам выше.
     */
    public static function deep(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
                continue;
            }

            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && self::isAssoc($value)
                && self::isAssoc($base[$key])
            ) {
                $base[$key] = self::deep($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * Слить произвольное число слоёв слева направо.
     */
    public static function layers(array ...$layers): array
    {
        $result = [];
        foreach ($layers as $layer) {
            $result = self::deep($result, $layer);
        }

        return $result;
    }

    /**
     * Ассоциативный ли массив. Пустой считается списком — такой слой заменяет значение целиком.
     */
    private static function isAssoc(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
