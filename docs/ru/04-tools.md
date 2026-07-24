**Язык:** [English](../04-tools.md) · **Русский**

# Инструменты (function calling)

Инструмент — это PHP-класс, который модель может вызвать сама. Модель не выполняет ваш код: она видит имя инструмента, его описание и список аргументов, а когда решает, что он нужен, присылает имя и аргументы. Дальше код вызывает инструмент, а результат уходит обратно в диалог.

Инструменты нужны там, где модель не может знать ответ: данные из базы, вызов внешнего API, расчёт по текущему состоянию, действие с побочным эффектом.

Эта страница — про один инструмент. Как собрать их в реестр и запустить цикл — [05-toolbox-and-runner.md](05-toolbox-and-runner.md).

## Минимальный инструмент

```php
<?php

namespace App\Llm\Tools;

use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class TimeNowTool extends AbstractTool
{
    public function getName(): string
    {
        return 'time_now';
    }

    public function getDescription(): string
    {
        return 'Текущие дата и время на сервере. Вызывай, когда нужен «сейчас».';
    }

    public function getParameters(): array
    {
        return [];   // аргументов нет
    }

    public function execute(array $args): Result
    {
        return Result::ok(['iso' => date('c')]);
    }
}
```

Четыре метода — и модель уже умеет узнавать время. `AbstractTool` закрывает остальные методы контракта разумными значениями по умолчанию.

## Инструмент с аргументами

```php
<?php

namespace App\Llm\Tools;

use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class GetWeatherTool extends AbstractTool
{
    private WeatherApi $api;

    public function __construct(WeatherApi $api)
    {
        $this->api = $api;
    }

    public function getName(): string
    {
        return 'get_weather';
    }

    public function getDescription(): string
    {
        return 'Текущая погода в одном городе. Вызывай, когда спрашивают про погоду, '
            . 'температуру или условия в конкретном месте.';
    }

    public function getParameters(): array
    {
        return [
            new Property('city', 'string', 'Название города, например «Москва»', true),
        ];
    }

    public function firstUseHint(): string
    {
        return 'get_weather возвращает {city, temperatureC, condition}. '
            . 'condition — одно из: clear, cloudy, rain, snow, storm. temperatureC — градусы Цельсия.';
    }

    public function execute(array $args): Result
    {
        $city = trim((string)($args['city'] ?? ''));
        if ($city === '') {
            return Result::error('Не указан город (city).');
        }

        $weather = $this->api->current($city);

        return Result::ok([
            'city'         => $city,
            'temperatureC' => $weather->temp,
            'condition'    => $weather->condition,
        ]);
    }

    public function shouldDisplay(array $args): bool
    {
        return true;
    }
}
```

Зависимости инструмент получает через конструктор — их передаёт тулбокс (см. [05-toolbox-and-runner.md](05-toolbox-and-runner.md)).

## Из чего состоит контракт

`Hameleon2x\Llm\Tool\ToolInterface`:

- **`getName(): string`** — имя функции для модели: `get_weather`. Только буквы, цифры, `_` и `-`. Менять его у работающего инструмента не стоит: на это имя ссылается сохранённая история диалогов.
- **`getDescription(): string`** — когда и зачем вызывать. Уходит в каждый запрос вместе со списком инструментов, поэтому пишите коротко и с явным триггером: «вызывай, когда спрашивают про погоду».
- **`getParameters(): array`** — список `Property`, по одному на аргумент. Из них собирается JSON Schema, по которой модель формирует вызов.
- **`execute(array $args): Result`** — собственно работа. `$args` — уже разобранный JSON от модели.
- **`firstUseHint(): string`** — пояснение, как читать **ответ** инструмента. Подмешивается в его результат при первом вызове в диалоге. По умолчанию пусто. Результат-объект получает пояснение соседним ключом, а результат-список на первом вызове убирается под ключ `Config::$firstUseResultKey` (по умолчанию `result`) — рядом с пояснением: добавить ключ в список нельзя, а терять пояснение не хочется.
- **`firstUseHintKey(): string`** — под каким ключом пояснение кладётся в результат. По умолчанию `hint_use`; поменяйте, если такой ключ занят вашими данными.
- **`shouldDisplay(array $args): bool`** — подсказка интерфейсу: показывать ли этот вызов пользователю. На исполнение не влияет.

`AbstractTool` реализует три последних метода значениями по умолчанию (`''`, `hint_use`, `false`), так что в простом инструменте достаточно четырёх первых.

## Описание против пояснения

Два текста решают разные задачи, и путать их не стоит.

`getDescription()` отвечает на вопрос «когда меня вызывать» и уходит в модель при **каждом** запросе — это плата токенами за каждый ход диалога. Держите его в одну-две строки.

`firstUseHint()` отвечает на вопрос «как читать мой ответ»: что значит `condition: storm`, что пустой массив `results` — это «ничего не найдено», в каких единицах температура. Он подмешивается прямо в результат при первом вызове инструмента, то есть платится один раз и только если инструмент реально понадобился. Заодно системный промт остаётся неизменным между ходами, и кеш промпта у провайдера продолжает работать.

## Аргументы: `Property`

```php
new Property(
    string       $name,
    string|array $type,               // 'string', 'integer', 'number', 'boolean', 'array', 'object'
                                      // или объединение: ['integer', 'null']
    ?string      $description = null,
    bool         $required = false,
    ?array       $items = null         // для type = 'array': схема элемента, ['type' => 'integer']
);
```

Примеры:

```php
new Property('city', 'string', 'Название города', true);
new Property('limit', 'integer', 'Сколько записей вернуть, по умолчанию 10');
new Property('ids', 'array', 'Идентификаторы задач', true, ['type' => 'integer']);
new Property('comment', ['string', 'null'], 'Комментарий, если есть');
```

Обязательные аргументы помечайте `required = true` честно: по этому признаку модель понимает, хватает ли ей данных для вызова.

## Результат: `Result`

У `execute()` три возможных исхода:

```php
Result::ok(['city' => 'Москва', 'temperatureC' => 7]);   // успех: данные уходят модели как JSON
Result::error('Не указан город (city).');                 // ошибка: модель увидит {"error": "..."}
Result::suspend();                                        // пауза: результат придёт извне
```

Ошибка инструмента — это не исключение. Возвращайте `Result::error()` с текстом, из которого модели понятно, что исправить: она увидит его на следующем ходу и сможет переспросить или вызвать инструмент иначе.

Если исключение всё же вылетит из `execute()`, цикл перехватит его, закроет вызов нейтральной ошибкой и запишет подробности в лог (`LLM tool threw an exception`). Текст исключения модели по умолчанию не показывается: его писали для разработчика, он бывает огромным и содержит внутренности (сообщение `PDOException` несёт полный SQL со значениями) — а история уходит провайдеру и повторяется на каждом следующем обороте.

Если ваши инструменты бросают осмысленные для модели исключения, включите `$config->exposeToolExceptions = true`: сообщение придёт модели одной строкой, обрезанное до 300 символов.

`Result::suspend()` останавливает цикл и ждёт внешнего ввода — например, ответа пользователя на уточняющий вопрос. Подробно: [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Аргументы приходят от модели, а не от вас

Модель может ошибиться в типе, пропустить обязательное поле или придумать значение. Проверяйте `$args` так же, как проверяли бы ввод из внешнего запроса:

```php
public function execute(array $args): Result
{
    $limit = (int)($args['limit'] ?? 10);
    if ($limit < 1 || $limit > 100) {
        return Result::error('limit должен быть от 1 до 100.');
    }
    // ...
}
```

От одного класса ошибок цикл защищает сам: если модель прислала аргументы с протёкшей разметкой вызова (`<parameter name="...">` внутри значения), инструмент не будет исполнен — модель получит ошибку и переотправит вызов. Это `Config::$toolArgsGuard`, он включён по умолчанию.

## См. также

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — реестр инструментов и запуск цикла.
- [06-events.md](06-events.md) — события `TOOL_CALL` и `TOOL_RESULT` во время работы инструмента.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — инструмент, который ждёт ответа пользователя.
