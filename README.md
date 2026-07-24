[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](README.ru.md)

# llm-orchestra

A PHP LLM client with a model catalog, retries and automatic switching to a backup model on failure, an agent loop with tool calling, typed errors and PSR-3 logging. Framework- and SDK-free — it talks to providers directly over `ext-curl`.

## Installation

```bash
composer require hameleon2x/llm-orchestra
```

## Minimal example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;

$orchestra = new Orchestra(Registry::fromArray([
    'providers' => ['openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...']],
    'models'    => ['mini'   => ['provider' => 'openai', 'name' => 'gpt-4o-mini']],
    'defaultModel' => 'mini',
]));

$response = $orchestra->execute(Request::simple('Answer briefly.', 'What is PHP?'));

echo $response->isSuccess() ? $response->content : $response->error->category;
```

Everything beyond `providers` and `models` is optional: the fallback chain, retry policy, generation params, pricing and tags are added when you need them.

## What's inside

- **A model catalog.** The provider is transport only; a model is a key, an API slug, its own params and policy. The same model behind two providers is two entries, so nothing gets mixed up. The config is validated as a whole at build time.
- **Retries and backup models.** A single retry level, tunable per error category, and one flat escalation chain per catalog.
- **Typed errors.** Category, HTTP status, provider code and raw body instead of parsing message text.
- **A three-layer response.** Typed data (`content`, `toolCalls`, `usage`), normalized provider data (`extra`) and the raw payload (`raw`) — a new provider field never requires a library release.
- **An agent loop.** Tool calls, turn and call limits, pausing for user input, events for your UI.

## Documentation

- Send my first request — [01-getting-started.md](docs/01-getting-started.md)
- Describe the model catalog and the fallback chain — [02-catalog-and-fallback.md](docs/02-catalog-and-fallback.md)
- Wire up PSR-3 logging (Monolog, Yii2, etc.) — [03-logging.md](docs/03-logging.md)
- Write my own tool (function calling) — [04-tools.md](docs/04-tools.md)
- Run the agent loop (tools + multiple turns) — [05-toolbox-and-runner.md](docs/05-toolbox-and-runner.md)
- Receive agent loop events (UI progress, DB logging) — [06-events.md](docs/06-events.md)
- Understand errors, retries and model switching — [10-error-handling.md](docs/10-error-handling.md)
- Pause for user input and resume (human-in-the-loop) — [13-human-in-the-loop.md](docs/13-human-in-the-loop.md)
- See the full documentation index — [docs/README.md](docs/README.md)

## Requirements

- PHP 7.4+
- `ext-curl`, `ext-json`, `ext-mbstring`
- `psr/log` ^1.1 || ^2.0 || ^3.0

## Versioning

- [CHANGELOG.md](CHANGELOG.md) — per-release notes.
- [UPGRADING.md](UPGRADING.md) — migration guide between versions.

## License

MIT — see [LICENSE](LICENSE).
