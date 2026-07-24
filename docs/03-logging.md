**Language:** **English** · [Русский](ru/03-logging.md)

# Logging

`Orchestra` and the transport write to a PSR-3 `LoggerInterface`. Logging is optional — without a logger, `Psr\Log\NullLogger` is used.

## Wiring

The logger is passed into the `Orchestra` constructor and propagates to every provider in the catalog.

```php
<?php
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Registry;
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$orchestra = new Orchestra(Registry::fromArray($config), $logger);
```

## What exactly is logged

Five entries, each with context as an associative array (PSR-3 style):

- `warning` **`LLM attempt failed`** — a model call attempt failed. Context: `model`, `provider`, `attempt`, `category`, `message`.
- `warning` **`LLM wait budget exhausted, stopping retries`** — the `maxWaitSeconds` wait budget is exhausted. Context: `model`, `maxWaitSeconds`.
- `info` **`LLM switching to next model in fallback chain`** — work has been handed over to the next model in the chain. Context: `from`, `to`, `category`.
- `error` **`LLM all attempts exhausted`** — retries and switches didn't help, the request failed. Context: `model`, `category`, `message`, `attempts`.
- `debug` **`LLM request` / `LLM response`** — the outgoing payload and the raw response; only when the provider has `'debug' => true`. Context: `url`, `payload` and `status`, `body`.

Levels are assigned like this: `warning` — a failure that a second attempt may fix; `info` — a model change; `error` — the final failure of the request.

The `category` key is a value from `Error\ErrorCategory`. It's convenient for building metrics from: how many timeouts, how much rate limiting, how often a fallback switch occurs.

## Debugging requests

The full payload and the raw response are logged at `debug` level if the provider has the flag enabled:

```php
'providers' => [
    'openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'debug' => true],
],
```

Don't keep it on permanently: prompts and answers land in the log in full.

## Example: Monolog

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

## Example: a bridge into Yii2

Yii2 logs through `Yii::info/warning/error`, which is not PSR-3. A thin adapter connects them — this code lives in your application, not in the package:

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

And where the executor is assembled:

```php
<?php
use app\components\Yii2PsrLogger;
use Hameleon2x\Llm\Orchestra;

$orchestra = new Orchestra($registry, new Yii2PsrLogger('llm'));
```

Different subsystems of one application are conveniently separated by category: `new Orchestra($registry, new Yii2PsrLogger('assistant'))` and `new Orchestra($registry, new Yii2PsrLogger('tasker'))` work on a shared catalog but log to different channels.

The same scheme fits any framework — implement `Psr\Log\LoggerInterface` (or extend `Psr\Log\AbstractLogger`).

## Progress instead of logs

Logs answer the question "what happened afterwards", while a UI needs "what is happening right now". For that, there is an attempt observer (`Orchestra::withObserver()`) and agent loop events — see [10-error-handling.md](10-error-handling.md) and [06-events.md](06-events.md).

## See also

- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — what stands behind these entries.
- [10-error-handling.md](10-error-handling.md) — error categories and the attempt log.
