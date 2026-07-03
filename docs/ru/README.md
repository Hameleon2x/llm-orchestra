**Язык:** [English](../README.md) · **Русский**

# Индекс документации

LLM-дружелюбная карта `docs/`. Найди страницу по своему симптому.

| Триггер / вопрос                                                                                | Страница                                                  |
|-------------------------------------------------------------------------------------------------|-----------------------------------------------------------|
| Установка, первый запрос, чтение ответа                                                         | [01-getting-started.md](01-getting-started.md)            |
| Различия провайдеров (OpenAI / OpenRouter / Requesty), fallback, повторы, `supportedModels`      | [02-providers-and-fallback.md](02-providers-and-fallback.md) |
| PSR-3 логирование (что пишется, пример Monolog, мост для Yii2)                                  | [03-logging.md](03-logging.md)                            |
| Написать тулзу, `firstUseHint()`, `Property`, `Result`                                          | [04-tools.md](04-tools.md)                                |
| `AbstractToolbox`, `Runner::run()`, `Config`, лимиты, `log_message`                             | [05-toolbox-and-runner.md](05-toolbox-and-runner.md)      |
| Стриминг событий `assistant_message` / `tool_call` / `tool_result`                              | [06-events.md](06-events.md)                              |
| Сериализация истории сообщений для транспорта фронт ↔ бэк                                       | [07-history-serialization.md](07-history-serialization.md) |
| Полный справочник по `Config`: лимиты, `toolChoice`, `plugins` OpenRouter                       | [08-config-reference.md](08-config-reference.md)          |
| DTO `Usage`, учёт токенов, расчёт стоимости                                                     | [09-usage-and-limits.md](09-usage-and-limits.md)          |
| Иерархия исключений, retry/backoff, enum `Status`                                               | [10-error-handling.md](10-error-handling.md)              |
| Замена `CurlChatClient` (мок для тестов, Guzzle, middleware)                                    | [11-custom-http-client.md](11-custom-http-client.md)      |
| Реализовать кастомный провайдер (наследовать `BaseProvider`)                                    | [12-custom-provider.md](12-custom-provider.md)            |
| Поставить цикл на паузу ради ввода пользователя (апрув, вопрос) и возобновить                   | [13-human-in-the-loop.md](13-human-in-the-loop.md)        |
| Слои, поток данных, зачем их столько                                                            | [architecture.md](architecture.md)                        |

Документы верхнего уровня: [`../../README.ru.md`](../../README.ru.md) (установка + быстрый старт), [`../../CHANGELOG.ru.md`](../../CHANGELOG.ru.md), [`../../UPGRADING.ru.md`](../../UPGRADING.ru.md).
