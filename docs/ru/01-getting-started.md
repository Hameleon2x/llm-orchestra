**Язык:** [English](../01-getting-started.md) · **Русский**

# Быстрый старт

Минимальный путь от `composer require` до рабочего вызова LLM.

## Установка

```bash
composer require hameleon2x/llm-orchestra
```

Требования: PHP 7.4+, `ext-curl`, `ext-json`, `ext-mbstring`, `psr/log`.

## Каталог и исполнитель

Две точки входа: `Registry` — каталог провайдеров и моделей, `Orchestra` — исполнитель запросов поверх каталога.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;

$registry = Registry::fromArray([
    'providers' => [
        'openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...'],
    ],
    'models' => [
        'mini' => ['provider' => 'openai', 'name' => 'gpt-4o-mini'],
    ],
    'defaultModel' => 'mini',
]);

$orchestra = new Orchestra($registry);
```

Обязательного здесь ровно столько, сколько видно: провайдер знает, куда стучаться и чем авторизоваться, модель — через какого провайдера идти и под каким слагом её знает API. Всё остальное — параметры генерации, политика повторов, цепочка запасных моделей — необязательно и добавляется по мере надобности (см. [02-catalog-and-fallback.md](02-catalog-and-fallback.md)).

Каталог проверяется целиком при сборке: ссылка на несуществующего провайдера или опечатка в цепочке фолбэка поднимут `LlmConfigException` сразу, а не в момент сбоя в проде.

## Отправка запроса

`Request::simple($system, $user)` — самый короткий конструктор. Для произвольной истории — `Request::messages($messages)`, для вызова инструментов — `Request::withTools(...)` (обычно через [`Agent\Runner`](05-toolbox-and-runner.md)).

```php
<?php
use Hameleon2x\Llm\Dto\Request;

$response = $orchestra->execute(Request::simple(
    'Ты помощник, отвечающий кратко.',
    'Объясни, что такое PHP, одним предложением.'
));
```

Второй аргумент `execute()` — ключ модели из каталога. Опущен — берётся `defaultModel`:

```php
$response = $orchestra->execute($request, 'mini');
```

## Чтение ответа

Успех — это отсутствие ошибки. При сбое `content` равен `null`, а в `error` лежит разобранная ошибка с категорией.

```php
<?php
if ($response->isSuccess()) {
    echo $response->content;
} else {
    fwrite(STDERR, "LLM failed: {$response->error->category} — {$response->error->message}\n");
}
```

### Что ещё есть в ответе

```php
$response->content;        // текст ответа; null при сбое или если пришли только вызовы инструментов
$response->toolCalls;      // ToolCall[] — что модель попросила вызвать
$response->usage;          // токены, кеш, размышления, стоимость
$response->modelKey;       // ключ модели каталога, которая ответила
$response->modelName;      // её слаг для API
$response->providerKey;    // через какой транспорт прошёл запрос
$response->attempts;       // журнал попыток: повторы и переключения моделей
$response->error;          // ErrorInfo с категорией сбоя; null при успехе

$response->extra('reasoning');                  // размышления модели, если она их вернула
$response->raw('choices.0.finish_reason');      // любое поле сырого ответа по пути
$response->finishReason();                      // stop, length, tool_calls…
$response->isTruncated();                       // ответ обрезан лимитом токенов
```

`modelKey` стоит запомнить: если при сбое сработало переключение на запасную модель, ответ придёт не от той, что вы запрашивали, и увидеть это можно только здесь. Подробности — [10-error-handling.md](10-error-handling.md) и [09-usage-and-limits.md](09-usage-and-limits.md).

## Параметры генерации

Задаются на трёх уровнях и сливаются по явности: каталог → модель → вызов.

```php
$request = Request::simple($system, $user)
    ->setTemperature(0.2)
    ->setMaxTokens(2000);
```

То же самое, но для всех запросов модели, — в каталоге:

```php
'models' => [
    'mini' => [
        'provider' => 'openai',
        'name'     => 'gpt-4o-mini',
        'params'   => ['temperature' => 0.2, 'maxTokens' => 2000],
    ],
],
```

## Провайдер-специфичные поля payload

Всё, для чего нет отдельного параметра, задаётся как дополнительные поля payload — на уровне провайдера, модели или вызова:

```php
$request->setExtraParams(['session_id' => 'run_42']);
```

Стандартные поля (`model`, `messages`, `temperature`, `top_p`, `max_tokens`, `tools`, `tool_choice`, `seed`, `stream`) через `extraParams` перезаписать нельзя — для них есть параметры генерации.

## Что дальше

- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — каталог целиком: политика повторов, цепочка запасных моделей, режимы моделей.
- [10-error-handling.md](10-error-handling.md) — категории ошибок и что с ними делать.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — агентский цикл с инструментами.
