**Language:** **English** · [Русский](ru/README.md)

# Documentation index

LLM-friendly map of `docs/`. Find the page that matches your symptom.

| Trigger / question                                                                          | Page                                                          |
|---------------------------------------------------------------------------------------------|---------------------------------------------------------------|
| Install, send first request, read the answer                                                | [01-getting-started.md](01-getting-started.md)                |
| Provider differences (OpenAI / OpenRouter / Requesty), fallback, retries, `supportedModels` | [02-providers-and-fallback.md](02-providers-and-fallback.md)  |
| PSR-3 logging (what's logged, Monolog example, Yii2 bridge)                                 | [03-logging.md](03-logging.md)                                |
| Write a tool, `appendToSystemPromptAfterUse()`, `Property`, `Result`                        | [04-tools.md](04-tools.md)                                    |
| `AbstractToolbox`, `Runner::run()`, `Config`, limits, `log_message`                         | [05-toolbox-and-runner.md](05-toolbox-and-runner.md)          |
| Stream `assistant_message` / `tool_call` / `tool_result` events                             | [06-events.md](06-events.md)                                  |
| Serialize message history for front ↔ back transport                                        | [07-history-serialization.md](07-history-serialization.md)    |
| Full `Config` reference: limits, `toolChoice`, OpenRouter `plugins`                         | [08-config-reference.md](08-config-reference.md)              |
| `Usage` DTO, token accounting, cost calculation                                             | [09-usage-and-limits.md](09-usage-and-limits.md)              |
| Exception hierarchy, retry/backoff, `Status` enum                                           | [10-error-handling.md](10-error-handling.md)                  |
| Replace `CurlChatClient` (mock for tests, Guzzle, middleware)                               | [11-custom-http-client.md](11-custom-http-client.md)          |
| Implement a custom provider (extend `BaseProvider`)                                         | [12-custom-provider.md](12-custom-provider.md)                |
| Pause the loop for user input (approval, ask-the-user), then resume                         | [13-human-in-the-loop.md](13-human-in-the-loop.md)            |
| Layers, data flow, why so many of them                                                      | [architecture.md](architecture.md)                            |

Root-level docs: [`../README.md`](../README.md) (install + quickstart), [`../CHANGELOG.md`](../CHANGELOG.md), [`../UPGRADING.md`](../UPGRADING.md).
