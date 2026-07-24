<?php

namespace Hameleon2x\Llm\Support;

use Hameleon2x\Llm\Exception\LlmConfigException;

/**
 * Проверка ключей секции конфигурации против белого списка.
 *
 * Неизвестный ключ — почти всегда опечатка, которая иначе молча потеряла бы настройку (`temperatur`
 * вместо `temperature`, `retrie` вместо `retries`). Одна точка проверки держит это поведение
 * одинаковым во всех `fromArray()` каталога и опций прогона. Произвольные данные приложения кладут
 * в специально отведённые ключи (`meta`, `tags`, `extraParams`), а не рядом со схемой.
 */
final class ConfigKeys
{
    /**
     * Бросить LlmConfigException, если в $config есть ключ вне $allowed.
     *
     * @param array    $config  разбираемая секция конфига
     * @param string[] $allowed допустимые ключи
     * @param string   $context человекочитаемое начало сообщения об ошибке («Модель «glm-4.6»»)
     */
    public static function assertKnown(array $config, array $allowed, string $context): void
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string)$key, $allowed, true)) {
                throw new LlmConfigException(
                    "{$context}: неизвестный ключ «{$key}». Допустимы: " . implode(', ', $allowed) . '.'
                );
            }
        }
    }
}
