**Language:** **English** · [Русский](ru/architecture.md)

# Architecture

How the package is layered, what each layer owns, and what flows through it.

## Layers

```
Agent      Runner / Toolbox / Tool / SystemPromptComposer  (multi-turn loop, tools)
   |
Client     fallback chain, PSR-3 logging, generation defaults
   |
Provider   Request -> wire format -> Response               (retry loop with backoff)
   |
Http       ChatClientInterface — POST /v1/chat/completions  (default: CurlChatClient)
   |
Network    HTTPS to OpenAI / OpenRouter / Requesty / yours
```

Each layer talks only to the one below. The boundaries are single PHP types:

| Boundary                  | Type / interface                                                   |
|---------------------------|--------------------------------------------------------------------|
| Agent ↔ Client            | [`Request`](../src/Dto/Request.php), [`Response`](../src/Dto/Response.php) |
| Client ↔ Provider         | [`ProviderInterface`](../src/Provider/ProviderInterface.php)       |
| Provider ↔ Http           | [`ChatClientInterface`](../src/Http/ChatClientInterface.php)       |
| Provider ↔ Agent (tools)  | [`ToolDefinition`](../src/Dto/ToolDefinition.php), [`ToolCall`](../src/Dto/ToolCall.php) |

## Flow: `Client::execute()`

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

Retries inside a single provider: 1s → 2s → 4s → 8s (cap 10s). Fallback between providers: `Client` moves on whenever a provider throws or returns a non-success `Response`. Neither layer throws to the caller — failures are encoded in `Response::$status` + `Response::$error`. See [docs/10-error-handling.md](10-error-handling.md).

## Flow: `Runner::run()`

```
caller --messages, toolbox, systemPromptFn, config--> Runner.run
  for each turn (up to config.maxTurns):
    systemPrompt = SystemPromptComposer.compose(base, history, toolbox)
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

`SystemPromptComposer` rebuilds the prompt each turn so `appendToSystemPromptAfterUse()` text for tools already used can be appended. `Tool\Dto\Result` is the typed return from every tool, serialised to JSON for the `tool` message. `Usage` accumulates token counters — see [docs/09-usage-and-limits.md](09-usage-and-limits.md).

## Source layout

```
src/
├── Client.php                              fallback chain, PSR-3 logging, withProviders()
├── Agent/
│   ├── Runner.php                          agent loop
│   ├── SystemPromptComposer.php            base prompt + tool-usage notes
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

## Why so many layers

- **Http vs Provider** — the HTTP transport (cURL, Guzzle, fake) is orthogonal to the API shape. See [docs/11-custom-http-client.md](11-custom-http-client.md).
- **Provider vs Client** — provider knows one wire format; client owns fallback and PSR-3. See [docs/02-providers-and-fallback.md](02-providers-and-fallback.md).
- **Client vs Agent** — `Client::execute()` is a one-shot RPC; `Runner` is a stateful loop with tools, prompt augmentation and per-run limits.
- **DTOs vs Factories** — typed DTOs (`Message`, `ToolCall`, `ToolDefinition`) plus factories that produce OpenAI-shaped arrays for both the provider wire and front-back transport. See [docs/07-history-serialization.md](07-history-serialization.md).

## See also

- [docs/01-getting-started.md](01-getting-started.md) — minimal end-to-end example.
- [docs/02-providers-and-fallback.md](02-providers-and-fallback.md) — provider priority and fallback.
- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the agent loop in detail.
- [docs/10-error-handling.md](10-error-handling.md) — failure modes and statuses.
- [docs/12-custom-provider.md](12-custom-provider.md) — adding a new API shape.
