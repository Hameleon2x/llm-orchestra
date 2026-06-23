**Язык:** [English](../04-tools.md) · **Русский**

# Тулзы (function calling)

Тулза — это PHP-класс, который модель может вызвать в ходе агентского цикла. Эта страница — про контракт `ToolInterface` и сопутствующие DTO. Запуск тулз внутри цикла описан в [05-toolbox-and-runner.md](05-toolbox-and-runner.md).

## Когда писать тулзу

Пиши тулзу, когда модели нужно что-то вне её обучающих данных: чтение из БД, удалённое API, расчёт по текущему состоянию, побочный эффект. Выполнение тулзы — обычный PHP: всё, что умеешь сделать в методе, можно завернуть в тулзу.

## Контракт

`Hameleon2x\Llm\Tool\ToolInterface`:

| Метод                                 | Возвращает       | Назначение                                                                                                    |
|---------------------------------------|------------------|---------------------------------------------------------------------------------------------------------------|
| `getName()`                           | `string`         | Имя функции, которое уходит модели (например `get_weather`). Должно укладываться в `[a-zA-Z0-9_-]`.           |
| `getDescription()`                    | `string`         | Когда и зачем модели вызывать эту тулзу. Идёт в списке `tools` каждого запроса.                                |
| `appendToSystemPromptAfterUse()`      | `string`         | Заметки, дописываемые в **system**-промт только после того, как тулзу хотя бы раз вызвали. Описывает форму *вывода*, а не входа. `''` — если ничего не дописывать. |
| `getParameters()`                     | `Property[]`     | JSON Schema параметров, по одному `Property` на аргумент.                                                     |
| `execute(array $args)`                | `Tool\Dto\Result`| Запустить тулзу; `$args` — раскодированный JSON от модели.                                                    |
| `shouldDisplay(array $args)`          | `bool`           | UI-хинт: показывать ли этот вызов в чате (виджет, превью). К выполнению отношения не имеет.                   |

### `AbstractTool`

`Hameleon2x\Llm\Tool\AbstractTool` — тонкий базовый класс с одним дефолтом: `shouldDisplay(): bool = false`. Всё остальное реализуешь сам.

### Почему `appendToSystemPromptAfterUse()`, а не `getDescription()`?

`getDescription()` лежит в массиве `tools` на каждом запросе и подталкивает модель к вызову («используй меня»). Держи описание коротким и сфокусированным на вызове.

`appendToSystemPromptAfterUse()` дописывается в **system**-промт только на тех ходах, где тулза уже появлялась в истории диалога (этим управляет `Agent\SystemPromptComposer`). Используй, чтобы напомнить модели, как читать собственный вывод: `temperatureC` — в градусах Цельсия, пустой массив `results` значит «ничего не найдено», `status: closed` значит «дело закрыто». Тогда на следующих ходах модель корректно интерпретирует результат и не приходится тратить токены, проговаривая это каждый раз заранее.

## `Property`

`Hameleon2x\Llm\Tool\Dto\Property` описывает одно свойство JSON Schema:

```php
new Property(
    string  $name,
    string|array $type,               // 'string', 'integer', 'number', 'boolean', 'array', 'object',
                                      // or a union like ['integer', 'null']
    ?string $description = null,
    bool    $required = false,
    ?array  $items = null             // for type='array': schema of element, e.g. ['type' => 'integer']
);
```

`Property[]` уходит в `Tool\SchemaBuilder::build()` (через toolbox) и собирается в `{ type: 'object', properties: { ... }, required: [...] }`.

## `Result`

`Hameleon2x\Llm\Tool\Dto\Result` — тип возврата для `execute()`:

```php
Result::ok(array $data = []);   // success — $data is a flat assoc array or list, serialised as-is
Result::error(string $message); // failure — wrapped as ['error' => $message] in the tool message
Result::suspend();              // пауза — результата ещё нет; поступит извне (human-in-the-loop)
```

`Result::toJsonArray()` вызывает `Runner` при сборке контента OpenAI-сообщения `tool`. Формат API намеренно простой: современные модели натренированы на соглашение `{"error": "..."}` для ошибок и голый JSON для успехов.

`Result::suspend()` — третий исход: тулза не возвращает данные, а просит цикл встать на паузу, пока не будет предоставлен внешний результат (ответ пользователя, апрув). См. [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Разобранный пример: `get_weather`

```php
<?php
declare(strict_types=1);

namespace App\Llm\Tools;

use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class GetWeatherTool extends AbstractTool
{
    public function getName(): string { return 'get_weather'; }

    public function getDescription(): string
    {
        return 'Get the current weather for a single city. Use when the user asks about weather, '
            . 'temperature, or conditions for a named place.';
    }

    public function appendToSystemPromptAfterUse(): string
    {
        return 'get_weather returns {city: string, temperatureC: number, condition: string}. '
            . '`condition` is one of: clear, cloudy, rain, snow, storm. `temperatureC` is in Celsius.';
    }

    public function getParameters(): array
    {
        return [
            new Property('city', 'string', 'City name in English, e.g. "Moscow"', true),
        ];
    }

    public function execute(array $args): Result
    {
        $city = trim((string)($args['city'] ?? ''));
        if ($city === '') {
            return Result::error('city is required');
        }
        // ... real implementation would hit a weather API here ...
        return Result::ok(['city' => $city, 'temperatureC' => 18, 'condition' => 'cloudy']);
    }

    public function shouldDisplay(array $args): bool { return true; }
}
```

Что делает каждый метод в контексте:

- `getName()` — попадает в OpenAI `function.name`. Не меняй просто так: история диалога ссылается на это имя.
- `getDescription()` — верхняя строка «что и когда». Явно упоминай триггер, чтобы модель выбирала тулзу на нужных ходах.
- `appendToSystemPromptAfterUse()` — схема вывода и пограничные случаи. Живёт в system-промте с первого использования.
- `getParameters()` — входы. Реально обязательные параметры помечай `required = true`; модель по этому понимает, хватает ли ей данных.
- `execute()` — валидируй `$args` оборонительно (модель умеет галлюцинировать). На любую ошибку возвращай `Result::error(...)` — текст уходит в диалог, и модель сможет восстановиться.
- `shouldDisplay()` — только UI-хинт; к выполнению отношения не имеет.

## См. также

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — как регистрировать тулзы и запускать цикл.
- [06-events.md](06-events.md) — события `TOOL_CALL` / `TOOL_RESULT` во время работы тулзы.
- [../../UPGRADING.ru.md](../../UPGRADING.ru.md) — миграция 0.1 → 0.2 (переименование `getSystemPromptDescription`).
