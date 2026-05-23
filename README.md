# llm-orchestra

PHP LLM client with provider fallback (OpenAI, OpenRouter, Requesty), agent loop with tool calling, typed tool results and PSR-3 logging. Framework-agnostic, no SDK dependencies — uses `ext-curl` directly. PHP 7.4+.

## Features

- **Provider fallback** — список провайдеров с приоритетом; при ошибке retryable идёт к следующему.
- **Exponential-backoff retries** внутри каждого провайдера (1s → 2s → 4s → 8s, потолок 10с).
- **OpenAI-compatible API** — `OpenAiProvider`, `OpenRouterProvider`, `RequestyProvider` из коробки.
- **Agent loop** (`Runner`) — цикл «запрос → tool-calls → повтор» с лимитами по оборотам и вызовам тулз.
- **Tool calling** — типизированный контракт (`ToolInterface`, `Result::ok / ::error`), сборщик JSON Schema, опциональный служебный параметр `log_message` для UI.
- **Token usage tracking** — суммарная статистика LLM-вызовов и токенов за прогон.
- **PSR-3 logging** — `LoggerInterface` опционально в конструктор; по умолчанию `NullLogger`.
- **Framework-agnostic** — нет зависимостей от Yii/Laravel/Symfony.

## Install

```bash
composer require hameleon2x/llm-orchestra
```

Требования: PHP 7.4+, `ext-curl`, `ext-json`, `psr/log`.

## Quickstart

### 1. Простой запрос

```php
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Provider\OpenAiProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];

$request = Request::simple('You are a helpful assistant', 'What is PHP?');
$response = $client->execute($request);

if ($response->isSuccess()) {
    echo $response->content;
}
```

### 2. Несколько провайдеров с fallback

```php
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Provider\OpenRouterProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenRouterProvider::class, 'token' => 'sk-or-...', 'model' => 'anthropic/claude-3.5-sonnet', 'priority' => 1],
    ['class' => OpenAiProvider::class,     'token' => 'sk-...',     'model' => 'gpt-4o-mini',                'priority' => 2],
];
```

Провайдеры сортируются по `priority` (меньше = выше). При ошибке retryable первый исчерпывает попытки, затем идёт второй.

### 3. Logger (PSR-3)

```php
$client = new Client($logger); // любая реализация LoggerInterface — Monolog, Yii-адаптер, ...
```

Тот же логгер автоматически пробрасывается во все провайдеры и пишет туда retry-попытки.

### 4. Tool calling — своя тулза

```php
use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class GetWeatherTool extends AbstractTool
{
    public function getName(): string { return 'get_weather'; }

    public function getDescription(): string
    {
        return 'Get current weather for a city.';
    }

    public function getSystemPromptDescription(): string
    {
        return 'get_weather возвращает {city, temperatureC, condition}.';
    }

    public function getParameters(): array
    {
        return [
            new Property('city', 'string', 'City name', true),
        ];
    }

    public function execute(array $args): Result
    {
        $city = (string)($args['city'] ?? '');
        if ($city === '') {
            return Result::error('city required');
        }
        return Result::ok([
            'city'         => $city,
            'temperatureC' => 18,
            'condition'    => 'cloudy',
        ]);
    }
}
```

### 5. Toolbox + агентский цикл

```php
use Hameleon2x\Llm\Agent\AbstractToolbox;
use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Dto\Message;

final class MyToolbox extends AbstractToolbox
{
    protected bool $withLogMessage = true; // добавит обязательный log_message в схему

    protected function buildTools(): array
    {
        return [new GetWeatherTool()];
    }
}

$runner = new Runner($client);

$result = $runner->run(
    [Message::user('What is the weather in Moscow?')],
    new MyToolbox(),
    fn() => 'You are a weather assistant. Use tools when needed.',
    new Config()
);

echo $result->content;

// Usage stats
echo "LLM calls: {$result->usage->llmCalls}\n";
echo "Total tokens: {$result->usage->totalTokens}\n";
```

### 6. Подписка на события цикла

```php
use Hameleon2x\Llm\Agent\Enum\Event;

$emit = function (string $event, string $content, array $meta) {
    switch ($event) {
        case Event::ASSISTANT_MESSAGE:
            // ассистент ответил (возможно с tool_calls)
            break;
        case Event::TOOL_CALL:
            // модель вызвала тулзу: $meta['tool'], $meta['args']
            break;
        case Event::TOOL_RESULT:
            // тулза вернула результат; $meta['ok'] — успех/ошибка тулзы
            break;
    }
};

$result = $runner->run($messages, $toolbox, $systemPromptFn, new Config(), $emit);
```

### 7. Сериализация истории (фронт ↔ бэк)

История — массив `Message[]`. Для передачи между фронтом и бэком используйте фабрики:

```php
use Hameleon2x\Llm\Factory\MessageFactory;

$array = array_map([MessageFactory::class, 'toArray'], $result->messages);
// ... отправили клиенту, получили обратно
$messages = array_map([MessageFactory::class, 'fromArray'], $array);
```

## Configuration

### Параметры провайдера (массив или конструктор)

| Ключ              | Тип         | Дефолт | Описание                                                            |
|-------------------|-------------|--------|---------------------------------------------------------------------|
| `class`           | class-string| —      | `OpenAiProvider`, `OpenRouterProvider`, `RequestyProvider`           |
| `token`           | string      | —      | API-токен                                                            |
| `model`           | string      | —      | Имя модели                                                          |
| `baseUrl`         | string      | —      | Базовый URL без `/v1` (для OpenAI-совместимых API)                  |
| `temperature`     | float       | 0.7    | Температура генерации                                               |
| `topP`            | float       | 0.95   | Top-p sampling                                                      |
| `maxTokens`       | int         | 1024   | Максимум токенов ответа                                             |
| `retryAttempts`   | int         | 3      | Сколько раз повторять retryable-ошибки                              |
| `timeout`         | int         | 30     | Таймаут HTTP-запроса, секунды                                       |
| `priority`        | int         | 999    | Приоритет (меньше = выше); fallback идёт по возрастанию             |
| `supportedModels` | array\|null | null   | Подстроки названий моделей; null — все. Для отсева на стороне фолбэка |

### Параметры агентского цикла (`Config`)

| Поле                | Тип                | Дефолт | Описание                                                  |
|---------------------|--------------------|--------|-----------------------------------------------------------|
| `maxTurns`          | int                | 10     | Максимум оборотов цикла                                   |
| `maxToolCalls`      | int                | 30     | Максимум вызовов тулз за прогон                           |
| `temperature`       | float\|null        | null   | null — дефолт провайдера                                  |
| `maxTokens`         | int\|null          | null   | null — дефолт провайдера                                  |
| `toolChoice`        | string\|array      | 'auto' | `'auto'`, `'required'`, `'none'` или конкретная тулза     |
| `plugins`           | array\|null        | null   | Плагины OpenRouter (напр. web search)                     |
| `limitNudgeMessage` | string             | …      | Сообщение при исчерпании лимита вызовов тулз              |
| `limitFallbackText` | string             | …      | Ответ, когда после добивки модель ничего не вернула        |
| `turnsExhaustedText`| string             | …      | Ответ при исчерпании лимита оборотов                       |

## Architecture

```
src/
├── Client.php             — главный entry point (provider fallback, retry)
├── Agent/
│   ├── Runner.php         — цикл «LLM → tools → повтор»
│   ├── AbstractToolbox.php— база для реестра тулз
│   ├── SystemPromptComposer.php
│   └── Dto/, Enum/
├── Tool/
│   ├── ToolInterface.php  — контракт тулзы
│   ├── AbstractTool.php
│   ├── SchemaBuilder.php  — JSON Schema из Property[]
│   └── Dto/Property, Dto/Result
├── Provider/              — OpenAi / OpenRouter / Requesty (наследники BaseProvider)
├── Http/                  — ChatClientInterface + CurlChatClient
├── Dto/                   — Message, Request, Response, ToolCall, ToolDefinition
├── Factory/               — DTO ↔ array (формат OpenAI API)
├── Enum/                  — Role, Status
└── Exception/             — LlmException + 3 наследника (Rate/Provider/Validation)
```

## License

MIT — см. [LICENSE](LICENSE).
