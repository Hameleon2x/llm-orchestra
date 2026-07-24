<?php

namespace Hameleon2x\Llm\Tool;

/**
 * Защита от «протёкшей» разметки формата вызова инструментов в аргументах.
 *
 * Часть моделей отдаёт вызов не нативным function calling, а текстом в XML-подобном формате, и
 * диалектов как минимум два — модель дрейфует между ними даже внутри одного вызова:
 *   - общий:   `<invoke name="...">`, `<parameter name="...">значение</parameter>`;
 *   - именной: `<data>значение</data>` (тег назван по имени параметра).
 * Если такую разметку не заметить, соседние параметры склеиваются в значение одного поля, часть
 * аргументов теряется, и инструмент отрабатывает на неполных данных.
 *
 * Runner проверяет аргументы перед исполнением и, найдя разметку, возвращает модели ошибку вместо
 * результата — модель переотправляет вызов, разложив параметры по аргументам. Каждая такая отбивка
 * расходует бюджет вызовов инструментов, поэтому зациклиться на ней прогон не может.
 */
final class ToolArgsGuard
{
    /**
     * Маркеры общего диалекта: открывающий и закрывающий `<parameter>` / `<invoke>`, в том числе
     * с пространством имён. В нормальном значении аргумента их не бывает.
     */
    private const GENERIC_MARKER = '~<\s*/?\s*(?:antml:)?(?:parameter\b|invoke\b)~i';

    /**
     * Сообщение модели. `{fields}` заменяется списком испорченных полей.
     */
    public string $messageTemplate = 'Похоже, вызов собран неверно: в поле(ях) «{fields}» попала разметка '
    . 'формата вызова (`<parameter name=…>` / `<invoke …>` или одноимённые параметрам теги `<имя>…</имя>`) — '
    . 'значит соседние параметры склеились в одно значение и часть аргументов потеряна. Переотправь этот же '
    . 'вызов, передав КАЖДЫЙ параметр отдельным аргументом, без XML-разметки в значениях.';

    /** @var string[] регулярные выражения-маркеры */
    private array $patterns;

    /**
     * @param string[] $patterns
     */
    private function __construct(array $patterns)
    {
        $this->patterns = $patterns;
    }

    /**
     * Проверка со встроенными маркерами; $extraPatterns добавляются к ним.
     *
     * @param string[] $extraPatterns
     */
    public static function default(array $extraPatterns = []): self
    {
        return new self(array_merge([self::GENERIC_MARKER], $extraPatterns));
    }

    /**
     * Проверка только по своим маркерам.
     *
     * @param string[] $patterns
     */
    public static function withPatterns(array $patterns): self
    {
        return new self($patterns);
    }

    /**
     * Текст ошибки для модели или null, если аргументы чистые.
     *
     * @param array    $args       аргументы вызова
     * @param string[] $paramNames имена параметров инструмента: одноимённые теги в значении — тоже
     *                             признак утечки. Пустой список — проверяются только общие маркеры.
     */
    public function findLeak(array $args, array $paramNames = []): ?string
    {
        $patterns = $this->patterns;
        $named = self::namedTagPattern($paramNames);
        if ($named !== null) {
            $patterns[] = $named;
        }

        $badFields = [];
        foreach ($args as $name => $value) {
            if (self::hasLeak($value, $patterns)) {
                $badFields[] = (string)$name;
            }
        }

        if ($badFields === []) {
            return null;
        }

        return str_replace('{fields}', implode('», «', $badFields), $this->messageTemplate);
    }

    /**
     * Регулярное выражение для тегов, названных по именам параметров: `<name>`, `<name …>`, `</name>`.
     * Имя тега должно совпадать целиком, поэтому `<dataset>` при параметре `data` не сработает.
     *
     * @param string[] $paramNames
     */
    private static function namedTagPattern(array $paramNames): ?string
    {
        $names = [];
        foreach ($paramNames as $name) {
            $name = (string)$name;
            if ($name !== '') {
                $names[] = preg_quote($name, '~');
            }
        }

        if ($names === []) {
            return null;
        }

        return '~<\s*/?\s*(?:' . implode('|', $names) . ')\b~i';
    }

    /**
     * Есть ли маркер в значении: строка либо вложенный массив строк.
     *
     * @param mixed    $value
     * @param string[] $patterns
     */
    private static function hasLeak($value, array $patterns): bool
    {
        if (is_string($value)) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value) === 1) {
                    return true;
                }
            }

            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::hasLeak($item, $patterns)) {
                    return true;
                }
            }
        }

        return false;
    }
}
