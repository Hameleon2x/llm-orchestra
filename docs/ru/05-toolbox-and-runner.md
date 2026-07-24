**Язык:** [English](../05-toolbox-and-runner.md) · **Русский**

# Инструменты и агентский цикл

Обычный запрос — это «спросил → получил текст». Агентский цикл нужен, когда модель должна сначала **сходить за данными**: посмотреть погоду, найти клиента в базе, посчитать что-то. Тогда разговор идёт так:

1. Мы отправляем модели вопрос и список доступных инструментов.
2. Модель отвечает не текстом, а просьбой: «вызови `get_weather` с аргументом `city = Москва`».
3. Мы выполняем инструмент у себя в коде и отправляем результат обратно.
4. Модель либо просит ещё что-то, либо отвечает пользователю.

Эти четыре шага и крутит `Agent\Runner`. Инструменты он берёт из **тулбокса** — реестра, который вы описываете сами. Как написать один инструмент, разобрано в [04-tools.md](04-tools.md); здесь — как собрать их вместе и запустить цикл.

## Полный пример

Скопируйте целиком, подставьте токен — заработает. Инструмент здесь самый простой, чтобы не отвлекать.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Agent\AbstractToolbox;
use Hameleon2x\Llm\Agent\Dto\RunOptions;
use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;
use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result as ToolResult;

// 1. Инструмент: что модель сможет вызвать.
final class GetWeatherTool extends AbstractTool
{
    public function getName(): string
    {
        return 'get_weather';
    }

    public function getDescription(): string
    {
        return 'Текущая погода в городе. Вызывай, когда пользователь спрашивает про погоду.';
    }

    public function getParameters(): array
    {
        return [new Property('city', 'string', 'Название города, например «Москва»', true)];
    }

    public function execute(array $args): ToolResult
    {
        // Здесь был бы запрос к погодному API.
        return ToolResult::ok(['city' => $args['city'] ?? '', 'temp' => 7, 'text' => 'облачно']);
    }
}

// 2. Тулбокс: реестр инструментов для этого прогона.
final class WeatherToolbox extends AbstractToolbox
{
    protected function buildTools(): array
    {
        return [new GetWeatherTool()];
    }
}

// 3. Каталог моделей и исполнитель — как в 01-getting-started.
$orchestra = new Orchestra(Registry::fromArray([
    'providers' => ['openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...']],
    'models'    => ['mini'   => ['provider' => 'openai', 'name' => 'gpt-4o-mini']],
    'defaultModel' => 'mini',
]));

// 4. Настройки прогона.
$options = new RunOptions();
$options->model = 'mini';
$options->maxTurns = 12;
$options->maxToolCalls = 10;
$options->params->temperature = 0.3;

// 5. Запуск.
$result = (new Runner($orchestra))->run(
    [Message::user('Какая сейчас погода в Москве?')],
    new WeatherToolbox(),
    static fn(): string => 'Ты кратко отвечаешь на вопросы о погоде. Факты бери из инструментов.',
    $options
);

echo $result->success ? $result->content : 'Сбой: ' . $result->error->category;
```

Модель сама решит, что для ответа нужен `get_weather`, вызовет его, получит `{"city":"Москва","temp":7,"text":"облачно"}` и сформулирует ответ пользователю.

## Как читать результат

`Runner::run()` возвращает `Agent\Dto\Result`. Полезные поля:

- **`$success`** — прогон дошёл до ответа. `false` при сбое вызова модели, при истечении срока прогона и при паузе на внешний ввод.
- **`$content`** — итоговый текст для пользователя; `null`, когда `$success` равен `false`.
- **`$error`** — `Error\ErrorInfo` с категорией сбоя, если он был. Разбирать текст ошибки не нужно, см. [10-error-handling.md](10-error-handling.md).
- **`$finish`** — почему цикл остановился: `Finish::COMPLETED`, `TOOL_LIMIT`, `TURNS_EXHAUSTED`, `DEADLINE`, `ERROR` или `SUSPENDED`.
- **`$messages`** — полная история после прогона (без системного сообщения). Сохраните её, если разговор продолжится.
- **`$turnsUsed`, `$toolCallsUsed`** — сколько оборотов и вызовов инструментов израсходовано.
- **`$usage`** — токены, стоимость и разбивка по моделям, см. [09-usage-and-limits.md](09-usage-and-limits.md).
- **`$modelKey`** — какая модель работала последней. Отличается от запрошенной, если при сбое произошло переключение на запасную.
- **`$attempts`** — журнал попыток вызова модели: повторы и переключения.
- **`$lastResponse`** — последний ответ модели целиком: размышления в `extra`, сырой ответ через `raw()`.
- **`$suspended`, `$pendingToolCallIds`** — прогон встал на паузу и ждёт внешнего ввода, см. [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Тулбокс

Тулбокс — это класс, который отдаёт циклу список инструментов и умеет исполнить любой из них по имени. Проще всего наследовать `AbstractToolbox` и реализовать один метод:

```php
<?php
use Hameleon2x\Llm\Agent\AbstractToolbox;

final class MyToolbox extends AbstractToolbox
{
    protected function buildTools(): array
    {
        return [
            new GetWeatherTool($this->httpClient),   // сюда удобно передавать свои сервисы
            new FindClientTool($this->clientRepository),
        ];
    }
}
```

`buildTools()` вызывается один раз и лениво — это место, где инструменты получают зависимости: репозитории, HTTP-клиенты, текущего пользователя.

Если ваш проект собирает инструменты иначе (например, читает их из базы), реализуйте `ToolboxInterface` напрямую — `Runner` работает с любой реализацией.

### Пояснение вызова для интерфейса: `log_message`

Часто в интерфейсе хочется показать не «вызван get_weather», а человеческую строку «Смотрю погоду в Москве…». Чтобы такую строку писала сама модель, включите в тулбоксе `log_message`:

```php
final class MyToolbox extends AbstractToolbox
{
    protected bool    $withLogMessage        = true;
    protected ?string $logMessageDescription = 'Короткое пояснение: что делаешь этим вызовом и зачем.';

    protected function buildTools(): array { /* ... */ }
}
```

Тогда в схему каждого инструмента добавится обязательный строковый параметр `log_message`, и он придёт вместе с остальными аргументами — в инструменте его можно читать или игнорировать, а в интерфейс он попадёт через событие `TOOL_CALL` ([06-events.md](06-events.md)).

## Сигнатура `run()`

```php
public function run(
    array            $messages,        // Message[] — история без системного сообщения
    ToolboxInterface $toolbox,
    callable         $systemPromptFn,  // fn(Message[] $history): string
    Config           $options,
    ?callable        $emit = null      // fn(string $event, string $content, array $meta): void
): Result
```

- **`$messages`** — история диалога. Системное сообщение сюда не кладут: его добавляет сам цикл.
- **`$systemPromptFn`** — функция, возвращающая системный промт. Вызывается на каждом обороте и получает текущую историю, поэтому промт можно строить динамически. Возвращённый текст уходит в модель как есть.
- **`$options`** — настройки прогона: модель, лимиты, параметры генерации. Полный разбор — [08-config-reference.md](08-config-reference.md).
- **`$emit`** — необязательный приёмник событий: прогресс в интерфейсе, запись диалога в базу ([06-events.md](06-events.md)).

## Лимиты

Два лимита защищают от бесконечной работы:

- **`maxTurns`** — сколько раз можно обратиться к модели. Один оборот — один запрос, даже если модель попросила в нём пять инструментов сразу.
- **`maxToolCalls`** — сколько инструментов можно исполнить за весь прогон.

Что происходит, когда они кончаются:

- **Исчерпан `maxToolCalls`.** Оставшиеся вызовы этого хода закрываются ошибкой, в историю добавляется сообщение `limitNudgeMessage` («данных больше не будет, дай итоговый ответ»), и делается ещё один запрос — уже без инструментов. Ответ модели становится результатом; если она вернула ход без текста, это сбой категории `empty_response` — а `limitFallbackText` останется для редкого случая, когда в ответе только незапрошенные вызовы инструментов. Этот запрос идёт сверх бюджета оборотов и `turnsUsed` не увеличивает. В обоих случаях `$success` равен `true`, а `$finish` — `Finish::TOOL_LIMIT`. Если же сам запрос не удался (сеть, лимит контекста, недоступность), прогон возвращает ошибку с категорией, как и на любом обороте, — заглушка вместо неё не подставляется.
- **Исчерпан `maxTurns`.** В историю дописывается `turnsExhaustedText`, он же попадает в `$content`. `$success` равен `true`, `$finish` — `Finish::TURNS_EXHAUSTED`.

Оба случая — не ошибка, а нормальное завершение по бюджету. Отличить их от полноценного ответа помогает `$finish`.

Третий ограничитель — срок: `$options->deadlineSeconds`. Он проверяется перед каждым оборотом, и при истечении прогон возвращает ошибку категории `deadline` вместе с полной историей: собранные результаты инструментов не теряются. Проверка стоит в начале оборота, а остаток срока передаётся исполнителю как потолок ожидания на вызов — поэтому повторы и переключения внутри оборота тоже ограничены. Вне проверки остаётся только добор неотвеченных вызовов при возобновлении.

## Подсказка при первом вызове инструмента

У инструмента бывает неочевидный формат ответа — например, поля `docId` и `sources[]`, которые модель должна использовать определённым образом. Такое пояснение не стоит держать в системном промте: он уходит в модель при каждом запросе и стоит токенов даже тогда, когда инструмент не используется.

Вместо этого цикл подмешивает пояснение в результат инструмента при **первом** его вызове в диалоге: `$toolbox->firstUseHint($name)` кладётся в JSON-ответ под ключом `$toolbox->firstUseHintKey($name)` (по умолчанию `hint_use`). Один раз за диалог, в хвост истории — начало запроса остаётся неизменным, и кеш промпта у провайдера продолжает срабатывать. Пояснение кладётся отдельным ключом. Инструменту, который отвечает списком, ключ добавить некуда, поэтому на первом вызове его список убирается под `RunOptions::$firstUseResultKey` (по умолчанию `result`), а пояснение ложится рядом: `{"hint_use": "…", "result": [...]}`. На следующих вызовах ответ снова обычный список.

## См. также

- [04-tools.md](04-tools.md) — как написать инструмент.
- [06-events.md](06-events.md) — события цикла: прогресс, повторы, переключение модели.
- [08-config-reference.md](08-config-reference.md) — все настройки прогона.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — пауза цикла ради ответа пользователя.
- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — как выбирается модель и что происходит при её сбое.
