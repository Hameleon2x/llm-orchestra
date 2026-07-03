**Язык:** [English](../05-toolbox-and-runner.md) · **Русский**

# Toolbox и Runner

`Agent\Runner` — это агентский цикл: дёрнуть модель, выполнить тулзы, о которых она попросила, дописать результаты в историю, повторять, пока модель не выдаст финальный ответ или не упрётся в лимит. `Toolbox` — реестр, из которого `Runner` берёт тулзы. Контракт `ToolInterface` описан в [04-tools.md](04-tools.md).

## `AbstractToolbox`

`Hameleon2x\Llm\Agent\AbstractToolbox` — дефолтная реализация `ToolboxInterface`. Наследуйся, реализуй `buildTools()`, при желании подкручивай `log_message`.

```php
<?php
use App\Llm\Tools\GetWeatherTool;
use Hameleon2x\Llm\Agent\AbstractToolbox;

final class MyToolbox extends AbstractToolbox
{
    // Optional: inject obligatory `log_message` into every tool's schema.
    protected bool    $withLogMessage        = true;
    protected ?string $logMessageDescription = 'Short note for the dialog UI: what you are doing and why.';

    // Called lazily once. Inject DI services into tool constructors here.
    protected function buildTools(): array
    {
        return [
            new GetWeatherTool(/* $someService, $repository, ... */),
            // ...
        ];
    }
}
```

`buildTools()` — DI-шов: тулзам обычно нужны реальные сервисы (HTTP-клиент, репозиторий, текущий пользователь, часы).

### `$withLogMessage` / `$logMessageDescription`

Когда `$withLogMessage = true`, `SchemaBuilder` подмешивает обязательный строковый параметр `log_message` в JSON Schema каждой тулзы. Модель обязана прикладывать к каждому вызову короткую человекочитаемую заметку — удобно для чат-UI, которые хотят рендерить «Смотрю погоду в Москве…», не вычисляя это из имени тулзы и аргументов.

Имя параметра фиксировано (`SchemaBuilder::LOG_MESSAGE_PARAM = 'log_message'`). По умолчанию описание берётся из русского текста в `SchemaBuilder::LOG_MESSAGE_DESCRIPTION_DEFAULT`; переопределяй через `$logMessageDescription`, чтобы совпадало с языком твоего промта. `log_message` пробрасывается в `execute($args)` как обычный аргумент — тулза может его читать или игнорировать.

## `Runner::run()`

```php
public function run(
    array            $messages,        // Message[]   — dialog history, no system message
    ToolboxInterface $toolbox,
    callable         $systemPromptFn,  // fn(Message[] $history): string
    Config           $config,
    ?callable        $emit = null      // fn(string $event, string $content, array $meta): void
): Result
```

| Параметр          | Заметки                                                                                                            |
|-------------------|--------------------------------------------------------------------------------------------------------------------|
| `$messages`       | `Message[]` без `system`-записи. Runner собирает system-сообщение каждый ход через `$systemPromptFn`.              |
| `$toolbox`        | Любой `ToolboxInterface`. Определения читаются один раз; `execute()` вызывается на каждый tool call.               |
| `$systemPromptFn` | Вызывается каждый ход с текущей историей. Верни системный промт — используется as-is (без аугментации по тулзам).    |
| `$config`         | `Agent\Dto\Config` — лимиты, оверрайды генерации, fallback-тексты (ниже).                                          |
| `$emit`           | Опциональный приёмник событий — см. [06-events.md](06-events.md).                                                  |

## `Config`

`Hameleon2x\Llm\Agent\Dto\Config` — ручки на один запуск:

| Поле                 | Тип            | По умолчанию | Значение                                                                                                |
|----------------------|----------------|--------------|---------------------------------------------------------------------------------------------------------|
| `maxTurns`           | `int`          | 10           | Жёсткий потолок на итерации цикла (1 итерация = 1 LLM-вызов + выполнение запрошенных тулз).              |
| `maxToolCalls`       | `int`          | 30           | Жёсткий потолок на суммарное число вызовов тулз за запуск.                                               |
| `temperature`        | `?float`       | `null`       | Переопределяет дефолт провайдера; `null` = не трогать.                                                   |
| `maxTokens`          | `?int`         | `null`       | То же для лимита токенов.                                                                                |
| `toolChoice`         | `string\|array`| `'auto'`     | `'auto'`, `'required'`, `'none'` или `['type' => 'function', 'function' => ['name' => 'foo']]`.          |
| `plugins`            | `?array`       | `null`       | Плагины OpenRouter (например, web search) — пробрасываются как есть.                                     |
| `limitNudgeMessage`  | `string`       | …            | User-сообщение, дописываемое перед финальным LLM-вызовом, когда исчерпан `maxToolCalls`.                 |
| `limitFallbackText`  | `string`       | …            | Используется, если тот финальный LLM-вызов ничего не вернул.                                             |
| `turnsExhaustedText` | `string`       | …            | Возвращается как ответ ассистента, когда достигнут `maxTurns`.                                           |

## `Result`

`Hameleon2x\Llm\Agent\Dto\Result` — что возвращает `Runner::run()`:

| Свойство         | Тип                 | Значение                                                                            |
|------------------|---------------------|-------------------------------------------------------------------------------------|
| `$success`       | `bool`              | `false` только если сам LLM-вызов провалился (`Response::isSuccess() === false`).   |
| `$content`       | `?string`           | Финальный текст ассистента при успехе. `null` при ошибке.                           |
| `$error`         | `?string`           | Текст ошибки при провале.                                                           |
| `$messages`      | `Message[]`         | Полный диалог после запуска (без system). Сохраняй, если планируешь продолжать.     |
| `$turnsUsed`     | `int`               | Потрачено итераций (1..`maxTurns`).                                                 |
| `$toolCallsUsed` | `int`               | Потрачено вызовов тулз (0..`maxToolCalls`).                                         |
| `$usage`         | `Agent\Dto\Usage`   | `llmCalls`, `promptTokens`, `completionTokens`, `totalTokens` за весь запуск.       |
| `$suspended`         | `bool`      | `true`, когда прогон встал на паузу на suspend-тулзе в ожидании внешнего ввода (human-in-the-loop). `$content` / `$error` — `null`. |
| `$pendingToolCallIds`| `string[]`  | При `$suspended` — id вызовов, чьи результаты нужно подать для возобновления; см. [13-human-in-the-loop.md](13-human-in-the-loop.md). |

Упор в `maxTurns` или `maxToolCalls` даёт `success = true` с одним из настроенных fallback-текстов в `$content` — это не ошибка, запуск завершился штатно. Смотри `$turnsUsed` / `$toolCallsUsed`, чтобы поймать насыщение.

## Пояснения по тулзам: хинты при первом вызове в результате

Каждый ход runner вызывает `$systemPromptFn($messages)` и берёт полученный системный промт as-is — он неизменен между оборотами. Пояснения по тулзам доставляются иначе: когда `executeToolCalls` собирает tool-сообщение с результатом, он проверяет, первый ли это вызов данной тулзы в истории (`isFirstUse`). Если да — зовёт `$toolbox->firstUseHint($name)` и, если текст непустой, кладёт его в JSON-результат под ключом `$toolbox->firstUseHintKey($name)` (дефолт `hint_use`). Пояснение едет вместе с собственным выводом тулзы, один раз за диалог, дописанное в хвост истории — префикс запроса остаётся байт-в-байт стабильным, и prompt-кеш провайдера продолжает попадать.

## Что происходит при исчерпании лимитов

- **`maxToolCalls` исчерпан посреди хода.** `Runner::finishOnToolLimit()` дописывает `Config::$limitNudgeMessage` как `user`-сообщение и делает один финальный LLM-вызов **без тулз**. Если модель ответит — это и будет ответ; иначе — `Config::$limitFallbackText`. В любом случае `success = true`.
- **Достигнут `maxTurns`.** Runner дописывает `Config::$turnsExhaustedText` как сообщение ассистента и возвращает `success = true`.

## Полный пример

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Llm\Tools\GetWeatherTool;
use App\Llm\Tools\TimeNowTool;
use Hameleon2x\Llm\Agent\AbstractToolbox;
use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Provider\OpenAiProvider;

final class WeatherToolbox extends AbstractToolbox
{
    protected bool $withLogMessage = true;
    protected function buildTools(): array
    {
        return [new GetWeatherTool(), new TimeNowTool()];
    }
}

$client = new Client();
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];

$config = new Config();
$config->maxTurns = 5;
$config->maxToolCalls = 10;
$config->temperature = 0.3;

$result = (new Runner($client))->run(
    [Message::user('What is the weather in Moscow right now?')],
    new WeatherToolbox(),
    static fn(array $history): string => 'You are a concise weather assistant. Use tools when you need facts.',
    $config
);

echo $result->success ? $result->content : "Run failed: {$result->error}";
printf(
    "\nturns=%d toolCalls=%d llmCalls=%d tokens=%d\n",
    $result->turnsUsed, $result->toolCallsUsed,
    $result->usage->llmCalls, $result->usage->totalTokens
);
```

## См. также

- [04-tools.md](04-tools.md) — контракт `ToolInterface`.
- [06-events.md](06-events.md) — `$emit`-callback для прогресса внутри цикла.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — пауза цикла на внешний ввод (`Result::suspend()`) и возобновление.
- [02-providers-and-fallback.md](02-providers-and-fallback.md) — как нижележащий `Client` выбирает провайдера.
- [03-logging.md](03-logging.md) — отдельный PSR-3 канал для повторов и fallback.
