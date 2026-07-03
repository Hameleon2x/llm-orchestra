[![en](https://img.shields.io/badge/lang-en-red.svg)](UPGRADING.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](UPGRADING.ru.md)

# Upgrading

Breaking changes between major versions. Per-release notes: [CHANGELOG.md](CHANGELOG.md).

## 0.2.x → 0.3.x

Tool notes are no longer appended to the system prompt — the `Runner` now injects them into the tool's RESULT on first use (a stable system prefix keeps the provider's prompt cache alive). Methods renamed, `SystemPromptComposer` removed.

Project-wide rename in your code:

```
ToolInterface::appendToSystemPromptAfterUse()  →  ToolInterface::firstUseHint()
ToolboxInterface::systemPromptAddition($name)  →  ToolboxInterface::firstUseHint($name)
```

- Update every class implementing `ToolInterface` (or extending `AbstractTool`) — otherwise PHP throws `Fatal error: ... contains N abstract methods`. A tool with no note can drop the method entirely: `AbstractTool::firstUseHint()` now returns `''` by default.
- A hand-rolled `ToolboxInterface` (not via `AbstractToolbox`): rename `systemPromptAddition()` to `firstUseHint()` and add `firstUseHintKey(string $name): string` (return `AbstractTool::DEFAULT_FIRST_USE_HINT_KEY` if the key doesn't matter).
- `Agent\SystemPromptComposer` is gone. If you used it (e.g. to render the "full" system prompt in a UI), render the base prompt from `$systemPromptFn` instead; tool notes now live inside tool results under `firstUseHintKey()` (default `hint_use`).
- Optional: if the default key `hint_use` collides with a field in a tool's result, override `firstUseHintKey()` in that tool.

## 0.1.x → 0.2.x

`ToolInterface::getSystemPromptDescription()` renamed to `ToolInterface::appendToSystemPromptAfterUse()`. Signature and semantics unchanged.

Project-wide rename in your code:

```
getSystemPromptDescription  →  appendToSystemPromptAfterUse
```

Every class implementing `ToolInterface` (or extending `AbstractTool`) must be updated — otherwise PHP throws `Fatal error: ... contains 1 abstract method`.
