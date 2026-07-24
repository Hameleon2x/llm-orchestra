[![en](https://img.shields.io/badge/lang-en-red.svg)](README.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](README.ru.md)

# llm-orchestra

PHP-клиент LLM с каталогом моделей, повторами и переключением на запасную модель при сбое, агентским циклом с вызовом инструментов, типизированными ошибками и PSR-3 логированием. Не зависит от фреймворков и SDK — работает напрямую через `ext-curl`.

## Установка

```bash
composer require hameleon2x/llm-orchestra
```

## Минимальный пример

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

$response = $orchestra->execute(Request::simple('Ты отвечаешь кратко.', 'Что такое PHP?'));

echo $response->isSuccess() ? $response->content : $response->error->category;
```

Всё, кроме `providers` и `models`, необязательно: цепочка запасных моделей, политика повторов, параметры генерации, цены и метки добавляются по мере надобности.

## Что внутри

- **Каталог моделей.** Провайдер — только транспорт; модель — ключ, слаг для API, свои параметры и политика. Одна и та же модель у двух провайдеров — две записи, ничего не путается. Конфиг проверяется целиком при сборке.
- **Повторы и запасные модели.** Один уровень повторов, настраиваемый по категориям ошибок, и одна плоская цепочка эскалации на каталог.
- **Типизированные ошибки.** Категория, HTTP-статус, код провайдера и сырое тело вместо разбора текста сообщения.
- **Ответ тремя слоями.** Типизированное (`content`, `toolCalls`, `usage`), нормализованные данные провайдера (`extra`) и сырой ответ (`raw`) — новое поле у провайдера не требует релиза библиотеки.
- **Агентский цикл.** Вызовы инструментов, лимиты оборотов и вызовов, пауза ради ввода пользователя, события для интерфейса.

## Документация

- Отправить первый запрос — [01-getting-started.md](docs/ru/01-getting-started.md)
- Описать каталог моделей и цепочку запасных — [02-catalog-and-fallback.md](docs/ru/02-catalog-and-fallback.md)
- Подключить PSR-3 логирование (Monolog, Yii2 и т. п.) — [03-logging.md](docs/ru/03-logging.md)
- Написать свой инструмент (function calling) — [04-tools.md](docs/ru/04-tools.md)
- Запустить агентский цикл (инструменты + несколько ходов) — [05-toolbox-and-runner.md](docs/ru/05-toolbox-and-runner.md)
- Получать события агентского цикла (прогресс в интерфейсе, лог в БД) — [06-events.md](docs/ru/06-events.md)
- Разобраться с ошибками, повторами и переключением моделей — [10-error-handling.md](docs/ru/10-error-handling.md)
- Пауза ради ввода пользователя и возобновление (human-in-the-loop) — [13-human-in-the-loop.md](docs/ru/13-human-in-the-loop.md)
- Посмотреть полный индекс документации — [docs/ru/README.md](docs/ru/README.md)

## Требования

- PHP 7.4+
- `ext-curl`, `ext-json`, `ext-mbstring`
- `psr/log` ^1.1 || ^2.0 || ^3.0

## Тесты

Проверки лежат в `tests/` и не требуют ничего, кроме самой библиотеки:

```bash
php tests/run.php            # все наборы
php tests/run.php Цикл       # только наборы и проверки, где встречается «Цикл»
```

## Версионирование

- [CHANGELOG.ru.md](CHANGELOG.ru.md) — описания релизов.
- [UPGRADING.ru.md](UPGRADING.ru.md) — руководство по миграции между версиями.

## Лицензия

MIT — см. [LICENSE](LICENSE).
