**Язык:** [English](../03-logging.md) · **Русский**

# Логирование

`Orchestra` и транспорт пишут в PSR-3 `LoggerInterface`. Логирование опционально — без логгера используется `Psr\Log\NullLogger`.

## Подключение

Логгер передаётся в конструктор `Orchestra` и расходится по всем провайдерам каталога.

```php
<?php
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Registry;
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$orchestra = new Orchestra(Registry::fromArray($config), $logger);
```

## Что именно логируется

Пять записей, каждая с контекстом в виде ассоциативного массива (стиль PSR-3):

- `warning` **`LLM attempt failed`** — попытка вызова модели не удалась. Контекст: `model`, `provider`, `attempt`, `category`, `message`.
- `warning` **`LLM wait budget exhausted, stopping retries`** — исчерпан бюджет ожидания `maxWaitSeconds`. Контекст: `model`, `maxWaitSeconds`.
- `info` **`LLM switching to next model in fallback chain`** — работа передана следующей модели цепочки. Контекст: `from`, `to`, `category`.
- `error` **`LLM all attempts exhausted`** — повторы и переключения не помогли, запрос провалился. Контекст: `model`, `category`, `message`, `attempts`.
- `debug` **`LLM request` / `LLM response`** — исходящий payload и сырой ответ; только при `'debug' => true` у провайдера. Контекст: `url`, `payload` и `status`, `body`.

Уровни расставлены так: `warning` — сбой, который может пройти со второй попытки; `info` — смена модели; `error` — окончательный провал запроса.

Ключ `category` — значение из `Error\ErrorCategory`. По нему удобно строить метрики: сколько таймаутов, сколько ограничений частоты, как часто срабатывает переключение на запасную модель.

## Отладка запросов

Полный payload и сырой ответ пишутся на уровне `debug`, если у провайдера включён флаг:

```php
'providers' => [
    'openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'debug' => true],
],
```

Держать включённым постоянно не стоит: в лог попадают промты и ответы целиком.

## Пример: Monolog

```php
<?php
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Registry;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

$logger = new Logger('llm');
$logger->pushHandler(new StreamHandler(__DIR__ . '/llm.log', Level::Warning));

$orchestra = new Orchestra(Registry::fromArray($config), $logger);
$response = $orchestra->execute(Request::simple('be brief', 'hi'));
```

## Пример: мост в Yii2

Yii2 логирует через `Yii::info/warning/error`, а это не PSR-3. Тонкий адаптер связывает их — этот код лежит в приложении, а не в пакете:

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

И там, где собирается исполнитель:

```php
<?php
use app\components\Yii2PsrLogger;
use Hameleon2x\Llm\Orchestra;

$orchestra = new Orchestra($registry, new Yii2PsrLogger('llm'));
```

Разные подсистемы одного приложения удобно разводить по категориям: `new Orchestra($registry, new Yii2PsrLogger('assistant'))` и `new Orchestra($registry, new Yii2PsrLogger('tasker'))` работают на общем каталоге, но пишут в разные каналы.

Та же схема подходит любому фреймворку — реализуйте `Psr\Log\LoggerInterface` (или наследуйте `Psr\Log\AbstractLogger`).

## Прогресс вместо логов

Логи отвечают на вопрос «что случилось потом», а интерфейсу нужно «что происходит сейчас». Для этого есть наблюдатель попыток (`Orchestra::withObserver()`) и события агентского цикла — см. [10-error-handling.md](10-error-handling.md) и [06-events.md](06-events.md).

## См. также

- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — что стоит за этими записями.
- [10-error-handling.md](10-error-handling.md) — категории ошибок и журнал попыток.
