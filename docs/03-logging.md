**Language:** **English** · [Русский](ru/03-logging.md)

# Logging

`Client` and `BaseProvider` write to a PSR-3 `LoggerInterface`. Logging is opt-in — without a logger both fall back to `Psr\Log\NullLogger`.

## Wiring

Pass the logger into the `Client` constructor. It is propagated automatically to every provider built from an array config.

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

If you construct providers yourself (`new OpenAiProvider(...)`) and put the instances directly into `$client->providers`, pass the logger as the last constructor argument — the `Client` does not retrofit pre-built instances.

## What gets logged

| Source                     | Level     | Event                                                                           |
|----------------------------|-----------|---------------------------------------------------------------------------------|
| `BaseProvider::execute()`  | `warning` | Retryable error caught; another attempt will follow (or attempts exhausted).    |
| `Client::execute()`        | `warning` | Provider returned `status !== SUCCESS`, falling back to the next.               |
| `Client::execute()`        | `warning` | Provider threw `LlmException`, falling back to the next.                        |
| `Client::execute()`        | `error`   | Provider threw an unexpected `Throwable`; logged with stack trace.              |
| `Client::execute()`        | `error`   | Every provider failed; aggregate report with `providers_attempted`.             |

`warning` is for retries and graceful fallbacks; `error` is reserved for unexpected exceptions and total failure.

Context keys (associative array, PSR-3 style):

| Message                                              | Context keys                                                        |
|------------------------------------------------------|---------------------------------------------------------------------|
| `LLM provider attempt failed` (provider)             | `provider`, `attempt`, `error`, `code`, `retryable`                 |
| `LLM provider returned unsuccessful response`        | `provider`, `status`, `error`                                       |
| `LLM provider threw exception during request`        | `provider`, `exception`, `message`                                  |
| `Unexpected exception while calling LLM provider`    | `provider`, `exception`, `message`, `trace`                         |
| `All LLM providers failed`                           | `providers_attempted`, `last_status`, `last_error`                  |

## Example: Monolog

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

## Example: Yii2 bridge

Yii2 logs through `Yii::info/warning/error`, which is not PSR-3. A thin adapter bridges the two — this lives in your application, not in the package:

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

Then where you build the client:

```php
<?php
use app\components\Yii2PsrLogger;
use Hameleon2x\Llm\Client;

$client = new Client(new Yii2PsrLogger('llm'));
```

The same pattern works for any framework — implement `Psr\Log\LoggerInterface` (or extend `Psr\Log\AbstractLogger`) and dispatch to your framework's API inside `log()`.

## See also

- [02-providers-and-fallback.md](02-providers-and-fallback.md) — the events these log entries describe.
- [06-events.md](06-events.md) — `Runner`'s separate emit-callback for in-loop progress (UI-facing, complementary).
