**Язык:** [English](../03-logging.md) · **Русский**

# Логирование

`Client` и `BaseProvider` пишут в PSR-3 `LoggerInterface`. Логирование опциональное — без логгера оба откатываются к `Psr\Log\NullLogger`.

## Подключение

Передай логгер в конструктор `Client`. Он автоматически разойдётся по всем провайдерам, собранным из массива-конфига.

```php
<?php
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$client = new Client($logger);
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];
```

Если собираешь провайдеров сам (`new OpenAiProvider(...)`) и кладёшь готовые экземпляры прямо в `$client->providers`, передавай логгер последним аргументом конструктора — `Client` не дописывает его в уже собранные экземпляры.

## Что именно логируется

| Источник                   | Уровень   | Событие                                                                         |
|----------------------------|-----------|---------------------------------------------------------------------------------|
| `BaseProvider::execute()`  | `warning` | Поймана retryable-ошибка; будет ещё одна попытка (или попытки исчерпаны).       |
| `Client::execute()`        | `warning` | Провайдер вернул `status !== SUCCESS`, переход к следующему.                    |
| `Client::execute()`        | `warning` | Провайдер бросил `LlmException`, переход к следующему.                          |
| `Client::execute()`        | `error`   | Провайдер бросил неожиданный `Throwable`; пишется со стеком.                    |
| `Client::execute()`        | `error`   | Все провайдеры упали; агрегированный отчёт с `providers_attempted`.             |

`warning` — для повторов и штатных fallback; `error` — для неожиданных исключений и полного провала.

Ключи контекста (ассоциативный массив, по стилю PSR-3):

| Сообщение                                            | Ключи контекста                                                     |
|------------------------------------------------------|---------------------------------------------------------------------|
| `LLM provider attempt failed` (провайдер)            | `provider`, `attempt`, `error`, `code`, `retryable`                 |
| `LLM provider returned unsuccessful response`        | `provider`, `status`, `error`                                       |
| `LLM provider threw exception during request`        | `provider`, `exception`, `message`                                  |
| `Unexpected exception while calling LLM provider`    | `provider`, `exception`, `message`, `trace`                         |
| `All LLM providers failed`                           | `providers_attempted`, `last_status`, `last_error`                  |

## Пример: Monolog

```php
<?php
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$logger = new Logger('llm');
$logger->pushHandler(new StreamHandler(__DIR__ . '/llm.log', Level::Warning));

$client = new Client($logger);
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];

$response = $client->execute(Request::simple('be brief', 'hi'));
```

## Пример: мост в Yii2

Yii2 логирует через `Yii::info/warning/error`, а это не PSR-3. Тонкий адаптер связывает их — этот код лежит в твоём приложении, а не в пакете:

```php
<?php
namespace app\components;

use Psr\Log\AbstractLogger;
use Yii;

final class Yii2PsrLogger extends AbstractLogger
{
    private string $category;

    public function __construct(string $category = 'llm')
    {
        $this->category = $category;
    }

    public function log($level, $message, array $context = []): void
    {
        $msg = (string)$message;
        if ($context !== []) {
            $msg .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        switch ($level) {
            case 'emergency': case 'alert': case 'critical': case 'error':
                Yii::error($msg, $this->category); return;
            case 'warning': case 'notice':
                Yii::warning($msg, $this->category); return;
            case 'info':
                Yii::info($msg, $this->category); return;
            default:
                Yii::debug($msg, $this->category);
        }
    }
}
```

И там, где собираешь клиент:

```php
<?php
use app\components\Yii2PsrLogger;
use Hameleon2x\Llm\Client;

$client = new Client(new Yii2PsrLogger('llm'));
```

Та же схема работает для любого фреймворка — реализуй `Psr\Log\LoggerInterface` (или наследуй `Psr\Log\AbstractLogger`) и внутри `log()` пробрасывай в API своего фреймворка.

## См. также

- [02-providers-and-fallback.md](02-providers-and-fallback.md) — что стоит за этими лог-записями.
- [06-events.md](06-events.md) — отдельный emit-callback `Runner` для прогресса внутри цикла (UI-ориентированный, дополняет PSR-3).
