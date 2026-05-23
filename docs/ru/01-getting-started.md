**Язык:** [English](../01-getting-started.md) · **Русский**

# Быстрый старт

Минимальный путь от `composer require` до рабочего вызова LLM.

## Установка

```bash
composer require hameleon2x/llm-orchestra
```

Требования: PHP 7.4+, `ext-curl`, `ext-json`, `psr/log`.

## Создание клиента

`Client` — точка входа. Провайдеры перечисляются в `$client->providers` либо как готовые экземпляры `ProviderInterface`, либо — что чаще — как массивы-конфиги с ленивой инициализацией.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Provider\OpenAiProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];
```

`class`, `token`, `model` обязательны; для остального дефолты подставятся автоматически — см. [02-providers-and-fallback.md](02-providers-and-fallback.md).

## Отправка запроса

`Request::simple($system, $user)` — самый короткий конструктор: одно system-сообщение + одно user-сообщение. Для произвольной истории используй `Request::messages($messages)`; для вызова тулз — `Request::withTools(...)` (обычно через `Agent\Runner`, см. [05-toolbox-and-runner.md](05-toolbox-and-runner.md)).

```php
<?php
use Hameleon2x\Llm\Dto\Request;

$response = $client->execute(Request::simple(
    'You are a helpful assistant.',
    'Explain what PHP is in one sentence.'
));
```

## Чтение ответа

Перед чтением `content` всегда проверяй `isSuccess()` — при ошибке `content` равен `null`, а текст ошибки лежит в `error`.

```php
<?php
if ($response->isSuccess()) {
    echo $response->content;
} else {
    fwrite(STDERR, "LLM failed: {$response->error}\n");
}
```

### Поверхность `Response`

| Свойство / метод                                                               | Значение                                                               |
|--------------------------------------------------------------------------------|------------------------------------------------------------------------|
| `$response->status`                                                            | Константа из `Hameleon2x\Llm\Enum\Status`: `SUCCESS`, `RATE_LIMIT`, `PROVIDER_ERROR`, `VALIDATION_ERROR`, `TIMEOUT`, `ERROR`. |
| `$response->isSuccess()`                                                       | Сокращение для `$status === Status::SUCCESS`.                          |
| `$response->content`                                                           | Текст ассистента. `null` при ошибке или если вернулись только вызовы тулз. |
| `$response->toolCalls`, `$response->hasToolCalls()`                            | `ToolCall[]` от модели.                                                |
| `$response->provider`, `$response->model`                                      | Какой провайдер/модель в итоге ответили.                               |
| `$response->error`                                                             | Строка ошибки, если `status !== SUCCESS`.                              |
| `getPromptTokens()`, `getCompletionTokens()`, `getTotalTokens()`               | Количество токенов из блока `usage` провайдера.                        |
| `getLatency()`                                                                 | Время в секундах внутри вызова провайдера (wall-clock).                |
| `$response->metadata`                                                          | Сырая мапа: `promptTokens`, `completionTokens`, `totalTokens`, `finishReason`, `latency`, `attempts`. |

## Второй провайдер: OpenRouter

OpenRouter и Requesty — drop-in замена: та же OpenAI-совместимая API, отличаются только базовые URL и каталоги моделей. Класс провайдера подставляет правильный `baseUrl` сам; переопределяй его только если работаешь через прокси.

```php
<?php
use Hameleon2x\Llm\Provider\OpenRouterProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => 'sk-or-...', 'model' => 'anthropic/claude-3.5-sonnet'],

    // To use a proxy / self-hosted gateway, add:
    // 'baseUrl' => 'https://my-proxy.example.com/openrouter',
];

$response = $client->execute(Request::simple('You are concise.', 'Name 3 PHP frameworks.'));
echo $response->content;
```

## См. также

- [02-providers-and-fallback.md](02-providers-and-fallback.md) — несколько провайдеров, порядок fallback, повторы.
- [03-logging.md](03-logging.md) — как ловить события повторов и переходов между провайдерами.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — многошаговые диалоги с вызовом тулз.
