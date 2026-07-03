**Язык:** [English](../architecture.md) · **Русский**

# Архитектура

Как пакет разделён на слои, что принадлежит каждому слою и что через него течёт.

## Слои

```
Agent      Runner / Toolbox / Tool  (multi-turn loop, tools)
   |
Client     fallback chain, PSR-3 logging, generation defaults
   |
Provider   Request -> wire format -> Response               (retry loop with backoff)
   |
Http       ChatClientInterface — POST /v1/chat/completions  (default: CurlChatClient)
   |
Network    HTTPS to OpenAI / OpenRouter / Requesty / yours
```

Каждый слой общается только с тем, что под ним. На границах — конкретные PHP-типы:

| Граница                   | Тип / интерфейс                                                    |
|---------------------------|--------------------------------------------------------------------|
| Agent ↔ Client            | [`Request`](../../src/Dto/Request.php), [`Response`](../../src/Dto/Response.php) |
| Client ↔ Provider         | [`ProviderInterface`](../../src/Provider/ProviderInterface.php)    |
| Provider ↔ Http           | [`ChatClientInterface`](../../src/Http/ChatClientInterface.php)    |
| Provider ↔ Agent (тулзы)  | [`ToolDefinition`](../../src/Dto/ToolDefinition.php), [`ToolCall`](../../src/Dto/ToolCall.php) |

## Поток: `Client::execute()`

```
caller --Request--> Client.execute
  for each provider in priority order:
    Provider.execute (BaseProvider)  retry loop (attempts × backoff)
      Provider.doExecute (OpenAiProvider / yours)
        Request -> API payload
        ChatClientInterface.chat -> raw JSON
        parse -> Response (+ metadata.latency/attempt)
    if Response.isSuccess() -> return
    else / on LlmException / on Throwable -> log, next provider
  all failed -> return last Response::error
caller <--Response--
```

Повторы внутри одного провайдера: 1с → 2с → 4с → 8с (потолок 10с). Fallback между провайдерами: `Client` переходит к следующему всякий раз, когда провайдер бросает исключение или возвращает неуспешный `Response`. Ни один слой не бросает исключения наружу — сбои закодированы в `Response::$status` + `Response::$error`. См. [docs/10-error-handling.md](10-error-handling.md).

## Поток: `Runner::run()`

```
caller --messages, toolbox, systemPromptFn, config--> Runner.run
  for each turn (up to config.maxTurns):
    systemPrompt = systemPromptFn(history)   // as-is, без изменений
    Response = Client.execute(Request{system + history + tools})
    Usage.add(Response)
    if !Response.isSuccess()      -> Result::error
    if !Response.hasToolCalls()   -> append assistant msg, Result::success
    for each tool_call:
      Toolbox.execute(name, args) -> Tool\Dto\Result
      append Message::tool(toolCallId, json(result))
      emit Event::TOOL_CALL / Event::TOOL_RESULT
      if maxToolCalls exhausted   -> finishOnToolLimit, Result::success
  maxTurns exhausted              -> Result::success with turnsExhaustedText
caller <--Result--
```

Системный промт неизменен между оборотами — `$systemPromptFn` используется as-is. `firstUseHint()` тулзы подмешивается в её **результат** (под ключом `firstUseHintKey()`, дефолт `hint_use`) при первом вызове этой тулзы в диалоге, поэтому пояснения по тулзам едут вместе с данными, а не меняют префикс промта. `Tool\Dto\Result` — типизированный возврат каждой тулзы, сериализуется в JSON для сообщения `tool`. `Usage` накапливает счётчики токенов — см. [docs/09-usage-and-limits.md](09-usage-and-limits.md).

## Структура исходников

```
src/
├── Client.php                              fallback chain, PSR-3 logging, withProviders()
├── Agent/
│   ├── Runner.php                          agent loop
│   ├── ToolboxInterface.php / AbstractToolbox.php
│   ├── Dto/{Config,Result,Usage}.php       per-run params / result / token counters
│   └── Enum/Event.php                      events emitted via $emit
├── Provider/
│   ├── ProviderInterface.php / BaseProvider.php  retry loop, allowlist, exception → Status
│   ├── OpenAiProvider.php                  OpenAI-compatible (default)
│   ├── OpenRouterProvider.php              OpenAiProvider + openrouter.ai
│   └── RequestyProvider.php                OpenAiProvider + router.requesty.ai
├── Http/{ChatClientInterface,CurlChatClient}.php
├── Dto/{Request,Response,Message,ToolCall,ToolDefinition}.php
├── Factory/{Message,ToolCall,ToolDefinition}Factory.php   ↔ array (OpenAI shape)
├── Tool/
│   ├── ToolInterface.php / AbstractTool.php
│   ├── SchemaBuilder.php                   Property[] → JSON Schema parameters
│   └── Dto/{Property,Result}.php           property descriptor / typed tool result
├── Exception/{LlmException,LlmProviderException,LlmRateLimitException,LlmValidationException}.php
└── Enum/{Role,Status}.php
```

## Зачем столько слоёв

- **Http vs Provider** — HTTP-транспорт (cURL, Guzzle, fake) ортогонален формату API. См. [docs/11-custom-http-client.md](11-custom-http-client.md).
- **Provider vs Client** — провайдер знает один формат API; клиент держит fallback и PSR-3. См. [docs/02-providers-and-fallback.md](02-providers-and-fallback.md).
- **Client vs Agent** — `Client::execute()` — одношаговый RPC; `Runner` — stateful-цикл с тулзами и лимитами на прогон.
- **DTOs vs Factories** — типизированные DTO (`Message`, `ToolCall`, `ToolDefinition`) плюс фабрики, выдающие массивы в форме OpenAI — и для формата API провайдера, и для транспорта между фронтом и бэком. См. [docs/07-history-serialization.md](07-history-serialization.md).

## См. также

- [docs/01-getting-started.md](01-getting-started.md) — минимальный сквозной пример.
- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — приоритет провайдеров и fallback.
- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — агентский цикл в деталях.
- [docs/10-error-handling.md](10-error-handling.md) — режимы отказа и статусы.
- [docs/12-custom-provider.md](12-custom-provider.md) — добавить новый формат API.
