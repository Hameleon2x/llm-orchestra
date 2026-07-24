**Язык:** [English](../06-events.md) · **Русский**

# События

`Runner::run()` принимает необязательный колбэк `$emit`, который срабатывает в нескольких точках каждого оборота. Через него ведут живой чат-интерфейс или сохраняют диалог по ходу работы.

## Сигнатура

```php
$emit = function (string $event, string $content, array $meta): void { /* ... */ };
$runner->run($messages, $toolbox, $systemPromptFn, $config, $emit);
```

Обычный `callable`; `$event` — одна из констант `Agent\Enum\Event`.

## События

`Hameleon2x\Llm\Agent\Enum\Event`: три события хода и два события о сбоях вызова модели.

### `Event::ASSISTANT_MESSAGE` — `'assistant_message'`

Срабатывает один раз за ход, сразу после того, как модель вернула ответ с `tool_calls`. (Финальный ответ без вызовов инструментов завершает цикл и не эмитится — он приходит в `Result::$content`.)

- `$content` — текст ассистента рядом с вызовами инструментов (может быть пустым).
- `$meta['tool_calls']` — массив вызовов в формате API OpenAI (`['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '{...}']]`), собранный через `Factory\ToolCallFactory::toArray()`.
- `$meta['extra']` — данные провайдера по карте `capture`: размышления модели (`reasoning`), ссылки, отказы.
- `$meta['usage']` — потребление этого хода (`Dto\Usage::toArray()`).
- `$meta['model']` — ключ модели, ответившей на этот ход. После переключения отличается от запрошенной.

### `Event::TOOL_CALL` — `'tool_call'`

Срабатывает один раз на каждый запрошенный моделью вызов — пачкой, в момент получения ответа ассистента, до какого-либо `execute()`. (При возобновлении перезапускаемые вызовы `TOOL_CALL` повторно не шлют — только их `TOOL_RESULT`.)

- `$content` — имя инструмента (оно же в `$meta['tool']`).
- `$meta['tool_call_id']` — `id` вызова; парный к соответствующему `TOOL_RESULT`.
- `$meta['tool']` — имя инструмента.
- `$meta['args']` — раскодированные аргументы как ассоциативный массив (`ToolCall::getArguments()`).

### `Event::TOOL_RESULT` — `'tool_result'`

Срабатывает один раз на каждый вызов инструмента: сразу после возврата из `execute()`, а для вызова, который не отработал (отклонён проверкой аргументов, не поместился в лимит вызовов или упал с исключением), — в момент, когда цикл закрывает его ошибкой.

- `$content` — `Result::toJsonArray()`, закодированный в JSON (`JSON_UNESCAPED_UNICODE`). Для успехов — данные `data`; для ошибок — `{"error":"..."}`. При первом вызове инструмента в диалоге сюда же попадает пояснение `firstUseHint()` под ключом `firstUseHintKey()`.
- `$meta['tool_call_id']` — тот же id, что и у соответствующего `TOOL_CALL`.
- `$meta['tool']` — имя инструмента.
- `$meta['ok']` — `bool`, значение `Tool\Dto\Result::$ok`. Отличает ошибку инструмента (инструмент отработал, но сообщил о провале) от успеха.
- `$meta['guard']` — `true`, если вызов отклонён проверкой аргументов и инструмент не исполнялся (см. `Config::$toolArgsGuard`). Интерфейсу это сигнал не рисовать виджет по битому вызову.

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

**Приостановленные вызовы (human-in-the-loop):** инструмент, вернувший `Result::suspend()`, всё равно эмитит `TOOL_CALL` (чтобы интерфейс отрисовал вопрос или виджет), но **не** `TOOL_RESULT` — результата ещё нет. После хода прогон останавливается. При возобновлении цикл дорешивает только неотвеченные вызовы и эмитит лишь их `TOOL_RESULT` (`TOOL_CALL` уже был отправлен один раз), поэтому сохранённые события не задваиваются. См. [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Сценарий: живой прогресс в интерфейсе

Отправляйте каждое событие в браузер по WebSocket, SSE или long-polling. На `ASSISTANT_MESSAGE` рисуйте пузырь ассистента, на `TOOL_CALL` — индикатор «выполняю X…», на `TOOL_RESULT` заменяйте его результатом.

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

`Result::$messages` уже даёт полную историю по итогам прогона, поэтому обычно хватает одного сохранения после возврата из `run()`. События пишут в базу тогда, когда нужен аудит-трейл построчно: отметки времени и частичный транскрипт на случай, если воркер умер посреди работы.

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

    $db->insert('llm_dialog_events', $row); // псевдокод
};
```

Если прогон прервут (таймаут воркера, деплой посреди работы), уже записанные события останутся в базе — вместо пустоты будет частичный транскрипт.

## См. также

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — откуда зовётся `$emit`.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — `TOOL_CALL` без `TOOL_RESULT`, когда инструмент встаёт на паузу.
- [03-logging.md](03-logging.md) — PSR-3 канал для повторов и переключений; дополняет этот поток для интерфейса.
