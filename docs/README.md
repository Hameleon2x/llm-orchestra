**Language:** **English** · [Русский](ru/README.md)

# llm-orchestra documentation

Read them in order if this is your first time with the package: **01 → 02 → 04 → 05**. The rest, as needed.

**Getting started**

- [01-getting-started.md](01-getting-started.md) — installation, a minimal catalog, the first request, reading the response.
- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — providers and models, generation settings, retry policy, the chain of backup models, the `capture` map.

**Tools and the agent loop**

- [04-tools.md](04-tools.md) — how to write a tool that the model can call.
- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the tool registry and the loop: turns, limits, history.
- [06-events.md](06-events.md) — loop events: UI progress, logging the dialog to a database, retries and model switching.
- [08-config-reference.md](08-config-reference.md) — all the settings of a single run.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — pausing for a user's answer and resuming.

**Operations**

- [03-logging.md](03-logging.md) — PSR-3: what is logged at which level, bridges to Monolog and Yii2.
- [09-usage-and-limits.md](09-usage-and-limits.md) — tokens, cost, limits and deadlines.
- [10-error-handling.md](10-error-handling.md) — error categories, retries, backup models, the attempt log.
- [07-history-serialization.md](07-history-serialization.md) — storing and restoring conversation history.

**Extending**

- [11-custom-http-client.md](11-custom-http-client.md) — a custom transport: PSR-18, proxies, a test client.
- [12-custom-provider.md](12-custom-provider.md) — a custom provider for a different API format.
- [architecture.md](architecture.md) — layers, responsibilities, and key decisions.

The package overview and a minimal example are in [README.md](../README.md). Release notes — [CHANGELOG.md](../CHANGELOG.md); migrating between versions — [UPGRADING.md](../UPGRADING.md).
