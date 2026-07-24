**Язык:** [English](../README.md) · **Русский**

# Документация llm-orchestra

Читать по порядку, если знакомитесь с пакетом впервые: **01 → 02 → 04 → 05**. Остальное — по мере надобности.

**Начало**

- [01-getting-started.md](01-getting-started.md) — установка, минимальный каталог, первый запрос, чтение ответа.
- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — провайдеры и модели, настройки генерации, политика повторов, цепочка запасных моделей, карта `capture`.

**Инструменты и агентский цикл**

- [04-tools.md](04-tools.md) — как написать инструмент, который вызывает модель.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — реестр инструментов и цикл: обороты, лимиты, история.
- [06-events.md](06-events.md) — события цикла: прогресс в интерфейсе, запись диалога в базу, повторы и смена модели.
- [08-config-reference.md](08-config-reference.md) — все настройки одного прогона.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — пауза ради ответа пользователя и возобновление.

**Эксплуатация**

- [03-logging.md](03-logging.md) — PSR-3: что и на каком уровне пишется, мосты в Monolog и Yii2.
- [09-usage-and-limits.md](09-usage-and-limits.md) — токены, стоимость, лимиты и сроки.
- [10-error-handling.md](10-error-handling.md) — категории ошибок, повторы, запасные модели, журнал попыток.
- [07-history-serialization.md](07-history-serialization.md) — хранение и восстановление истории диалога.

**Расширение**

- [11-custom-http-client.md](11-custom-http-client.md) — свой транспорт: PSR-18, прокси, клиент для тестов.
- [12-custom-provider.md](12-custom-provider.md) — свой провайдер под другой формат API.
- [architecture.md](architecture.md) — слои, ответственности и принятые решения.

Обзор пакета и минимальный пример — в [README.ru.md](../../README.ru.md). Изменения по версиям — [CHANGELOG.ru.md](../../CHANGELOG.ru.md), миграция между версиями — [UPGRADING.ru.md](../../UPGRADING.ru.md).
