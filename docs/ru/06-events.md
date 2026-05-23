**Язык:** [English](../06-events.md) · **Русский**

# События

`Runner::run()` принимает опциональный `$emit`-callback, который срабатывает в трёх точках каждой итерации. Используй его, чтобы вести живой чат-UI или сохранять диалог по ходу выполнения.

## Сигнатура

```php
$emit = function (string $event, string $content, array $meta): void { /* ... */ };
$runner->run($messages, $toolbox, $systemPromptFn, $config, $emit);
```

Просто `callable`; `$event` — одна из констант `Agent\Enum\Event`.

## Три события

`Hameleon2x\Llm\Agent\Enum\Event`:

### `Event::ASSISTANT_MESSAGE` — `'assistant_message'`

Срабатывает один раз за ход, сразу после того, как модель вернула ответ с `tool_calls`. (Финальный ответ без вызова тулз завершает цикл и не эмитится — он приходит в `Result::$content`.)

- `$content` — текст ассистента рядом с вызовами тулз (может быть пустым).
- `$meta['tool_calls']` — массив tool calls в формате API OpenAI (`['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '{...}']]`), собранный через `Factory\ToolCallFactory::toArray()`.

### `Event::TOOL_CALL` — `'tool_call'`

Срабатывает один раз на каждый вызов тулзы, прямо перед запуском `execute()`.

- `$content` — имя тулзы (оно же в `$meta['tool']`).
- `$meta['tool_call_id']` — OpenAI `id`; парный к соответствующему `TOOL_RESULT`.
- `$meta['tool']` — имя тулзы.
- `$meta['args']` — раскодированные аргументы как ассоциативный массив (`ToolCall::getArguments()`).

### `Event::TOOL_RESULT` — `'tool_result'`

Срабатывает один раз на каждый вызов тулзы, сразу после возврата из `execute()`.

- `$content` — `Result::toJsonArray()`, закодированный в JSON (`JSON_UNESCAPED_UNICODE`). Для успехов — payload `data`; для ошибок — `{"error":"..."}`.
- `$meta['tool_call_id']` — тот же id, что и у соответствующего `TOOL_CALL`.
- `$meta['tool']` — имя тулзы.
- `$meta['ok']` — `bool`, значение `Tool\Dto\Result::$ok`. Отличает ошибки тулзы (тулза отработала, но сообщила о провале) от успехов.

Порядок внутри одного хода: `ASSISTANT_MESSAGE` → `TOOL_CALL` → `TOOL_RESULT` → (следующая пара `TOOL_CALL` / `TOOL_RESULT`, если запросили больше одной тулзы) → цикл продолжается.

## Сценарий: живой прогресс в UI

Шли каждое событие в браузер по WebSocket / SSE / long-polling. Рисуй пузырь ассистента на `ASSISTANT_MESSAGE`, индикатор «calling X...» на `TOOL_CALL`, заменяй его результатом на `TOOL_RESULT`.

```php
<?php
use Hameleon2x\Llm\Agent\Enum\Event;

$emit = function (string $event, string $content, array $meta) use ($channel): void {
    switch ($event) {
        case Event::ASSISTANT_MESSAGE:
            $channel->send(['kind' => 'assistant', 'text' => $content, 'tool_calls' => $meta['tool_calls']]);
            return;
        case Event::TOOL_CALL:
            $channel->send([
                'kind' => 'tool_call', 'tool_call_id' => $meta['tool_call_id'],
                'tool' => $meta['tool'], 'args' => $meta['args'],
            ]);
            return;
        case Event::TOOL_RESULT:
            $channel->send([
                'kind' => 'tool_result', 'tool_call_id' => $meta['tool_call_id'],
                'tool' => $meta['tool'], 'ok' => $meta['ok'], 'json' => $content,
            ]);
            return;
    }
};
```

## Сценарий: сохранение диалога в БД

`Result::$messages` уже даёт полную историю по итогам запуска, поэтому одного сохранения после возврата `run()` обычно хватает. Стримь события в БД, когда нужен row-per-event аудит-трейл (таймстемпы, частичный транскрипт на случай, если воркер умер посреди запуска):

```php
<?php
use Hameleon2x\Llm\Agent\Enum\Event;

$dialogId = 42;

$emit = function (string $event, string $content, array $meta) use ($db, $dialogId): void {
    $row = ['dialog_id' => $dialogId, 'event' => $event, 'content' => $content, 'created_at' => microtime(true)];

    switch ($event) {
        case Event::ASSISTANT_MESSAGE:
            $row['meta'] = json_encode(['tool_calls' => $meta['tool_calls']], JSON_UNESCAPED_UNICODE);
            break;
        case Event::TOOL_CALL:
            $row['tool_call_id'] = $meta['tool_call_id'];
            $row['tool']         = $meta['tool'];
            $row['meta']         = json_encode(['args' => $meta['args']], JSON_UNESCAPED_UNICODE);
            break;
        case Event::TOOL_RESULT:
            $row['tool_call_id'] = $meta['tool_call_id'];
            $row['tool']         = $meta['tool'];
            $row['ok']           = (int)$meta['ok'];
            break;
    }

    $db->insert('llm_dialog_events', $row); // pseudocode
};
```

Если запуск убьют (таймаут воркера, деплой посреди работы), уже записанные события всё равно в БД — у тебя останется частичный транскрипт, а не пустота.

## См. также

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — откуда зовётся `$emit`.
- [03-logging.md](03-logging.md) — PSR-3 канал для повторов / fallback; дополняет этот UI-ориентированный поток.
