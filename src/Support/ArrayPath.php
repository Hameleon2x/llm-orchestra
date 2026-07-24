<?php

namespace Hameleon2x\Llm\Support;

/**
 * Доступ к вложенным значениям массива по строковому пути с точками: `choices.0.message.content`.
 *
 * Нужен там, где структура принадлежит не нам, а провайдеру: карта capture в конфиге модели
 * описывает путь к полю сырого ответа, и код библиотеки не обязан знать это поле заранее.
 */
final class ArrayPath
{
    /**
     * Значение по пути `a.b.0.c` или $default, если по пути ничего нет.
     *
     * @return mixed
     */
    public static function get(array $data, string $path, $default = null)
    {
        if ($path === '') {
            return $default;
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Первое непустое значение из списка путей. Позволяет описать одно поле, которое разные
     * провайдеры называют по-разному (`reasoning_content` у одного, `reasoning` у другого).
     *
     * @param string[] $paths
     * @return mixed
     */
    public static function first(array $data, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = self::get($data, (string)$path);
            if ($value !== null && $value !== '' && $value !== []) {
                return $value;
            }
        }

        return $default;
    }
}
