**Язык:** [English](../08-config-reference.md) · **Русский**

# Справочник по конфигу агента

Полный справочник по [`Agent\Dto\Config`](../../src/Agent/Dto/Config.php) — набор параметров для одного вызова `Runner::run()`.

## Поля

| Поле                   | Тип             | По умолчанию                                                         | Описание                                                                    |
|------------------------|-----------------|----------------------------------------------------------------------|-----------------------------------------------------------------------------|
| `maxTurns`             | `int`           | `10`                                                                 | Жёсткий лимит итераций агентского цикла (один запрос к LLM = один ход).     |
| `maxToolCalls`         | `int`           | `30`                                                                 | Суммарный лимит на вызовы тулз за весь run (не на ход).                     |
| `temperature`          | `?float`        | `null`                                                               | Если `null`, используется значение по умолчанию провайдера.                 |
| `maxTokens`            | `?int`          | `null`                                                               | Если `null`, используется значение по умолчанию провайдера.                 |
| `toolChoice`           | `string\|array` | `'auto'`                                                             | `'auto'`, `'required'`, `'none'` или принудительная функция (массив, см. ниже). |
| `plugins`              | `?array`        | `null`                                                               | Payload OpenRouter-плагинов (например, web search). `null` — без плагинов.  |
| `limitNudgeMessage`    | `string`        | `'Лимит обращений к инструментам исчерпан. Дай итоговый ответ ...'`  | User-сообщение, добавляемое при достижении `maxToolCalls` (см. ниже).        |
| `limitFallbackText`    | `string`        | `'Не удалось завершить за допустимое число вызовов инструментов.'`   | Запасной ответ, когда запрос-«подталкивание» вернул пусто.                  |
| `turnsExhaustedText`   | `string`        | `'Не удалось завершить за допустимое число итераций.'`               | Финальный ответ при достижении `maxTurns`.                                  |

Все поля публичные — задавайте напрямую, без сеттеров и конструктора:

```php
use Hameleon2x\Llm\Agent\Dto\Config;

$config = new Config();
$config->maxTurns     = 6;
$config->maxToolCalls = 12;
$config->temperature  = 0.2;
```

## `maxTurns` — что такое ход

Один ход — это один запрос к LLM. Цикл:

1. Собрать системный промт.
2. Один раз вызвать `Client::execute()`.
3. Если в ответе нет вызовов тулз — вернуть успех.
4. Иначе выполнить все вызовы тулз из ответа и начать следующий ход.

Несколько вызовов тулз внутри одного сообщения ассистента считаются за **один** ход, но потребляют несколько единиц из `maxToolCalls`.

## `maxToolCalls` и «подталкивание»

`maxToolCalls` уменьшается с каждым выполненным вызовом тулзы по всем ходам. Когда счётчик обнуляется посреди хода, `Runner` уходит в ветку завершения по лимиту:

1. Добавить `Message::user(limitNudgeMessage)` в историю.
2. Отправить ещё один запрос **без** тулз (без `tools` / без `tool_choice`).
3. Если модель вернула непустой ответ — отдать его как успешный результат.
4. Иначе вернуть `limitFallbackText` как успешный результат.

Расход токенов этого дополнительного вызова добавляется в `Result::$usage` — см. [docs/09-usage-and-limits.md](09-usage-and-limits.md).

## `turnsExhaustedText`

Если `maxTurns` достигнут без завершающего ответа, `Runner` возвращает успешный `Result`, у которого `content` равен `turnsExhaustedText`. Полная история (включая последние результаты тулз) сохраняется в `$result->messages`.

## `temperature` и `maxTokens`

Оба — опциональные переопределения. Если оставлено `null`, провайдер сначала смотрит на свой конструкторный аргумент, затем на `Client::$defaultTemperature` / `Client::$defaultMaxTokens`. `topP` нельзя переопределить на уровне run — задавайте его на клиенте или провайдере.

## `toolChoice`

Прозрачно передаётся в OpenAI-совместимый параметр `tool_choice`.

```php
$config->toolChoice = 'auto';     // model decides
$config->toolChoice = 'required'; // model MUST call a tool on the next turn
$config->toolChoice = 'none';     // tools are listed but cannot be called

// Force a specific function:
$config->toolChoice = [
    'type'     => 'function',
    'function' => ['name' => 'get_weather'],
];
```

Форма с принудительной функцией отправляется как есть — следите, чтобы структура соответствовала API вашего провайдера.

## `plugins` (OpenRouter)

OpenRouter даёт серверные плагины (web search и т. п.) через поле запроса `plugins`. Пример для web search:

```php
$config->plugins = [
    [
        'id'            => 'web',
        'max_results'   => 5,
        'search_prompt' => 'Search the web for recent information about the user question.',
    ],
];
```

`plugins` учитывается только если выбранный провайдер принимает это поле. Обычный OpenAI его игнорирует.

## См. также

- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — полный разбор `Runner`.
- [docs/09-usage-and-limits.md](09-usage-and-limits.md) — как счётчики лимитов выглядят в `Result::$usage`.
- [docs/10-error-handling.md](10-error-handling.md) — как `Runner` сообщает об ошибках, когда дело не в лимитах.
