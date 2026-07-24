**Language:** **English** · [Русский](ru/architecture.md)

# Architecture

A map of the package: what it consists of, who is responsible for what, and how a single request travels.

## Layers

```
Agent\Runner            agent loop: turns, tools, limits, pausing for external input
      │
Orchestra               model selection, retries, switching to the next model in the chain
      │
Registry                catalog: providers, models, default params, policy, fallback chain
      │
Provider\*              API format: payload → HTTP → response parsing
      │
Http\ChatClientInterface  transport (cURL by default)
```

Each layer below knows nothing about the layer above it. The provider doesn't decide whether to retry the request; `Orchestra` doesn't know about tools; `Runner` doesn't know which model ended up answering — it takes the key from the response.

## Responsibilities

- **`Registry`** — the catalog and its validation, resolving keys and aliases, the default policy and chain. Doesn't execute requests.
- **`Orchestra`** — merging settings, retries, model switching, the attempt log, writing to PSR-3. Doesn't know about the API format or the dialog content.
- **`Provider\*`** — building the payload and parsing the response, classifying failures. Doesn't retry and doesn't pick the model.
- **`Http\CurlChatClient`** — sending HTTP and transport errors. Doesn't parse the response body.
- **`Agent\Runner`** — the turn loop, executing tools, limits, resuming. Retries and model switching are not its concern.
- **`Tool\*`, `Agent\Toolbox*`** — tool schemas and execution. Don't talk to the model.

## The path of a single request

1. `Orchestra::execute($request, $modelKey)` resolves the model through the catalog (key, alias, or `defaultModel`).
2. `ResolvedCall::build()` merges three levels: catalog → model → call. Generation params by explicitness, arbitrary fields and headers recursively, `unsupported` stripped on top of everything.
3. The provider builds the payload, calls the transport, parses the response, and applies the `capture` map.
4. Success is a `Response` with `content`/`toolCalls`, `usage`, `extra`, and the raw response. Failure is an `LlmException` with a category.
5. `Orchestra` records the attempt in the log, notifies the observer, and decides: retry, hand off to the next model in the chain, or return the error.
6. `Runner` (when it's the one driving) executes tool calls, appends to the history, and moves to the next turn — now on the model that answered.

## Key decisions

**The unit of choice is the model.** A provider describes the transport; a model is what gets called. The same model behind two providers is two catalog entries, so slugs never collide and neither `supportedModels` nor `priority` is needed.

**One flat fallback chain.** Models have no continuation lists of their own — otherwise you'd have to decide whose list wins on a nested failure. Models already tried are skipped, and the number of switches is capped.

**One retry level.** The transport runs no loop of its own; retries are counted by the model policy. Two levels would multiply, and waiting time would become unpredictable.

**An error is a category.** Provider texts are unstable, so the only place that parses them is `ErrorMapper`, and what comes out is an `ErrorInfo` with a category, a status, and the raw body.

**Nothing in the response is thrown away.** Only what the engine works on is typed; everything else is available through `extra` (normalized by the `capture` map) and `raw` (as received). A new field from a provider never requires a library release.

**Nothing propagates outward.** `Orchestra` and `Runner` return a result with the failure inside; exceptions remain an internal mechanism of providers.

## State

The package holds no state between calls. `Registry` and `Orchestra` are immutable with respect to configuration (`with*` return copies), and `Runner` keeps no history — it arrives and is returned as an array of messages. A suspended run resumes through the same `run()` with tool answers appended; there's no separate resume API.

## Directory map

```
src/
  Registry.php            the catalog
  Orchestra.php           execution with policy
  Config/                 definitions: provider, model, params, policy
  Dto/                    Request, Response, Message, ToolCall, Usage, AttemptLog, ResolvedCall
  Error/                  ErrorCategory, ErrorInfo, ErrorMapper
  Exception/              LlmException, LlmConfigException
  Http/                   ChatClientInterface, CurlChatClient
  Provider/               ProviderInterface, BaseProvider, OpenAi/OpenRouter/Requesty
  Agent/                  Runner, Config, Result, Finish, Event, Toolbox
  Tool/                   AbstractTool, SchemaBuilder, ToolArgsGuard, Dto
  Factory/                Message/ToolCall/ToolDefinition ↔ array
  Support/                ArrayPath, Merge, Sleeper
```

## See also

- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — the catalog in full.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the agent loop.
- [10-error-handling.md](10-error-handling.md) — errors and retries.
