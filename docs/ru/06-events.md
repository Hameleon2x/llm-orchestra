**Язык:** [English](../06-events.md) · **Русский**

# События

`Runner::run()` принимает опциональный `$emit`-callback, который срабатывает в трёх точках каждой итерации. Используй его, чтобы вести живой чат-UI или сохранять диалог по ходу выполнения.

## Сигнатура

```php
$emit = function (string $event, string $content, array $meta): void { /* ... */ };
$runner->run($messages, $toolbox, $systemPromptFn, $config, $emit);
```

Просто `callable`; `$event` — одна из констант `Agent\Enum\Event`.

## События

`Hameleon2x\Llm\Agent\Enum\Event`: три события хода и два события о сбоях вызова модели.

### `Event::ASSISTANT_MESSAGE` — `'assistant_message'`

Срабатывает один раз за ход, сразу после того, как модель вернула ответ с `tool_calls`. (Финальный ответ без вызова тулз завершает цикл и не эмитится — он приходит в `Result::$content`.)

- `$content` — текст ассистента рядом с вызовами тулз (может быть пустым).
- `$meta['tool_calls']` — массив tool calls в формате API OpenAI (`['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '{...}']]`), собранный через `Factory\ToolCallFactory::toArray()`.
- `$meta['extra']` — данные провайдера по карте `capture`: размышления модели (`reasoning`), ссылки, отказы.
- `$meta['usage']` — потребление этого хода (`Dto\Usage::toArray()`).
- `$meta['model']` — ключ модели, ответившей на этот ход. После переключения отличается от запрошенной.

### `Event::TOOL_CALL` — `'tool_call'`

Срабатывает один раз на каждый запрошенный моделью вызов — пачкой, в момент получения ответа ассистента, до какого-либо `execute()`. (При возобновлении перезапускаемые вызовы `TOOL_CALL` повторно не шлют — только их `TOOL_RESULT`.)

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
- `$meta['guard']` — `true`, если вызов отклонён проверкой аргументов и тулза не исполнялась (см. `Config::$toolArgsGuard`). Интерфейсу это сигнал не рисовать виджет по битому вызову.

### `Event::ATTEMPT_FAILED` — `'attempt_failed'`

Попытка вызова модели не удалась. Приходит и на промежуточные неудачи (за которыми будет повтор), и на последнюю.

- `$content` — категория ошибки (`Error\ErrorCategory`).
- `$meta['model']`, `$meta['provider']` — где произошёл сбой.
- `$meta['attempt']` — номер попытки этой моделью.
- `$meta['category']`, `$meta['message']` — категория и техническое сообщение.
- `$meta['will_retry']` — будет ли повтор; `$meta['delay']` — через сколько секунд.

Показывать «повторяю запрос» стоит только при `will_retry`: иначе следом придёт либо переключение модели, либо ошибка прогона.

### `Event::MODEL_FALLBACK` — `'model_fallback'`

Работа передана следующей модели цепочки: повторы предыдущей не помогли.

- `$content` — ключ новой модели.
- `$meta['from']`, `$meta['to']` — ключи прежней и новой модели.

Дальше прогон продолжается на новой модели (`Config::$stickyFallback`), поэтому событие приходит один раз на переключение, а не на каждый следующий ход.

Порядок внутри одного хода: `ASSISTANT_MESSAGE` → по одному `TOOL_CALL` на каждый запрошенный вызов (все сразу, при получении ответа модели) → по одному `TOOL_RESULT` на вызов по мере исполнения → цикл продолжается.

**Приостановленные вызовы (human-in-the-loop):** тулза, вернувшая `Result::suspend()`, всё равно эмитит `TOOL_CALL` (чтобы UI отрисовал вопрос/виджет), но **не** `TOOL_RESULT` — результата ещё нет. После хода прогон останавливается. При возобновлении раннер дорешивает только неотвеченные вызовы и эмитит лишь их `TOOL_RESULT` (`TOOL_CALL` уже был отправлен один раз), так что сохранённые события не задваиваются. См. [13-human-in-the-loop.md](13-human-in-the-loop.md).

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
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — `TOOL_CALL` без `TOOL_RESULT`, когда тулза встаёт на паузу.
- [03-logging.md](03-logging.md) — PSR-3 канал для повторов / fallback; дополняет этот UI-ориентированный поток.
