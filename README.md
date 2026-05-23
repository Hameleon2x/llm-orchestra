[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](README.ru.md)

# llm-orchestra

PHP LLM client with provider fallback (OpenAI, OpenRouter, Requesty), an agent loop with tool calling and typed tool results, and PSR-3 logging. Framework-agnostic, no SDK dependencies — uses `ext-curl` directly.

## Install

```bash
composer require hameleon2x/llm-orchestra
```

## Minimal example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Provider\OpenAiProvider;

$client = new Client();
$client->providers = [
    ['class' => OpenAiProvider::class, 'token' => 'sk-...', 'model' => 'gpt-4o-mini'],
];

$response = $client->execute(Request::simple('You are a helpful assistant', 'What is PHP?'));
if ($response->isSuccess()) {
    echo $response->content;
}
```

## Documentation

| I want to...                                                  | Read                                                                    |
|---------------------------------------------------------------|-------------------------------------------------------------------------|
| Send my first request                                         | [docs/01-getting-started.md](docs/01-getting-started.md)                |
| Configure providers and fallback order                        | [docs/02-providers-and-fallback.md](docs/02-providers-and-fallback.md)  |
| Plug in PSR-3 logging (Monolog, Yii2, etc.)                   | [docs/03-logging.md](docs/03-logging.md)                                |
| Write my own tool (function calling)                          | [docs/04-tools.md](docs/04-tools.md)                                    |
| Run an agent loop (tools + multi-turn)                        | [docs/05-toolbox-and-runner.md](docs/05-toolbox-and-runner.md)          |
| Stream events from the agent loop (UI progress, DB logging)   | [docs/06-events.md](docs/06-events.md)                                  |
| See the full doc index                                        | [docs/README.md](docs/README.md)                                        |

## Requirements

- PHP 7.4+
- `ext-curl`, `ext-json`
- `psr/log` ^1.1 || ^2.0 || ^3.0

## Versioning

- [CHANGELOG.md](CHANGELOG.md) — release notes.
- [UPGRADING.md](UPGRADING.md) — major-version migration guide.

## License

MIT — see [LICENSE](LICENSE).
