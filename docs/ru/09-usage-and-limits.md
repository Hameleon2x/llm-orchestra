**Язык:** [English](../09-usage-and-limits.md) · **Русский**

# Учёт расхода и стоимость

Как счётчики токенов агрегируются за один прогон агента и как превратить их в деньги.

## DTO `Usage`

Каждый вызов `Runner::run()` возвращает [`Result`](../../src/Agent/Dto/Result.php), у которого поле `$usage` — [`Usage`](../../src/Agent/Dto/Usage.php):

| Поле               | Тип    | Значение                                                                |
|--------------------|--------|-------------------------------------------------------------------------|
| `llmCalls`         | `int`  | Количество запросов к LLM за прогон (включая «подталкивание» при лимите). |
| `promptTokens`     | `int`  | Сумма `prompt_tokens` по всем ответам.                                  |
| `completionTokens` | `int`  | Сумма `completion_tokens` по всем ответам.                              |
| `totalTokens`      | `int`  | Сумма `total_tokens` по всем ответам.                                   |

`Usage::add(Response $r)` вызывает `Runner` для каждого ответа — успешного или нет. Провайдеры отдают метаданные расхода и на неудачных ответах (или нули, если не отдают), так что счётчики отражают всё, что прошло по сети.

## Что попадает в счётчики

- Каждый обычный ход — да.
- Дополнительный вызов «завершить без тулз», срабатывающий при упоре в `maxToolCalls`, — да.
- Ответы, пришедшие как ошибки, но с метаданными `usage`, — да (добавляются как есть).
- Локальные fallback'и провайдеров (когда провайдер бросает исключение и `Client` переходит к следующему) — нет: они не дают `Response::success(...)`, и `Runner` всё равно прерывается на первом же неудачном ответе (см. [docs/10-error-handling.md](10-error-handling.md)).

## Чтение usage после прогона

```php
<?php
use Hameleon2x\Llm\Agent\Runner;
use Psr\Log\LoggerInterface;

/** @var Runner $runner */
/** @var LoggerInterface $logger */
$result = $runner->run($messages, $toolbox, $systemPromptFn, $config);

$logger->info('agent run finished', [
    'success'           => $result->success,
    'turns_used'        => $result->turnsUsed,
    'tool_calls_used'   => $result->toolCallsUsed,
    'llm_calls'         => $result->usage->llmCalls,
    'prompt_tokens'     => $result->usage->promptTokens,
    'completion_tokens' => $result->usage->completionTokens,
    'total_tokens'      => $result->usage->totalTokens,
]);
```

Для одиночного вызова `Client::execute()` (без агентского цикла) те же числа читаются из `Response`:

```php
$response->getPromptTokens();
$response->getCompletionTokens();
$response->getTotalTokens();
$response->getLatency();           // seconds, set by BaseProvider
$response->metadata['finishReason'] ?? null;
```

## Расчёт стоимости

Встроенного калькулятора цен в пакете нет — цены меняются слишком часто и зависят от провайдера. Шаблон:

```php
<?php
// Pricing taken from the provider docs (USD per 1M tokens, example values).
$prices = [
    'gpt-4o-mini' => ['prompt' => 0.150, 'completion' => 0.600],
    'gpt-4o'      => ['prompt' => 2.500, 'completion' => 10.000],
];

$p    = $prices[$model] ?? null;
$cost = $p === null
    ? 0.0
    : ($result->usage->promptTokens     / 1_000_000) * $p['prompt']
    + ($result->usage->completionTokens / 1_000_000) * $p['completion'];
```

Если нужно в нескольких местах — заверните в свой `CostCalculator` на стороне приложения.

## Оговорки

- `Usage` **не** отслеживает, какая модель сгенерировала каждый вызов. Если в вашем прогоне возможен fallback между провайдерами с разными ценами — логируйте метаданные по каждому ответу самостоятельно.
- Числа идут напрямую от провайдера. Попадания в кэш, скидки за prompt caching и подобное отражаются, только если провайдер передаёт их в `usage`.

## См. также

- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — где заполняется `Usage`.
- [docs/08-config-reference.md](08-config-reference.md) — лимиты, ограничивающие прогон.
- [docs/10-error-handling.md](10-error-handling.md) — режимы отказа и как ведёт себя usage при ошибках.
