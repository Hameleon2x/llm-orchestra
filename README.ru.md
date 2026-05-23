[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](README.ru.md)

# llm-orchestra

PHP-клиент LLM с fallback между провайдерами (OpenAI, OpenRouter, Requesty), агентским циклом с вызовом тулз и типизированными результатами тулз, плюс PSR-3 логирование. Не зависит от фреймворков и SDK — работает напрямую через `ext-curl`.

## Установка

```bash
composer require hameleon2x/llm-orchestra
```

## Минимальный пример

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

## Документация

| Хочу...                                                       | Читать                                                                  |
|---------------------------------------------------------------|-------------------------------------------------------------------------|
| Отправить первый запрос                                       | [docs/01-getting-started.md](docs/01-getting-started.md)                |
| Настроить провайдеров и порядок fallback                      | [docs/02-providers-and-fallback.md](docs/02-providers-and-fallback.md)  |
| Подключить PSR-3 логирование (Monolog, Yii2 и т. п.)          | [docs/03-logging.md](docs/03-logging.md)                                |
| Написать свою тулзу (function calling)                        | [docs/04-tools.md](docs/04-tools.md)                                    |
| Запустить агентский цикл (тулзы + несколько ходов)            | [docs/05-toolbox-and-runner.md](docs/05-toolbox-and-runner.md)          |
| Получать события агентского цикла (прогресс в UI, лог в БД)   | [docs/06-events.md](docs/06-events.md)                                  |
| Посмотреть полный индекс документации                         | [docs/README.md](docs/README.md)                                        |

## Требования

- PHP 7.4+
- `ext-curl`, `ext-json`
- `psr/log` ^1.1 || ^2.0 || ^3.0

## Версионирование

- [CHANGELOG.md](CHANGELOG.md) — описания релизов.
- [UPGRADING.md](UPGRADING.md) — руководство по миграции между мажорными версиями.

## Лицензия

MIT — см. [LICENSE](LICENSE).
