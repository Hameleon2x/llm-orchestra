[![en](https://img.shields.io/badge/lang-en-red.svg)](UPGRADING.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](UPGRADING.ru.md)

# Upgrading

Breaking changes between major versions. Per-release notes: [CHANGELOG.md](CHANGELOG.md).

## 0.1.x → 0.2.x

`ToolInterface::getSystemPromptDescription()` renamed to `ToolInterface::appendToSystemPromptAfterUse()`. Signature and semantics unchanged.

Project-wide rename in your code:

```
getSystemPromptDescription  →  appendToSystemPromptAfterUse
```

Every class implementing `ToolInterface` (or extending `AbstractTool`) must be updated — otherwise PHP throws `Fatal error: ... contains 1 abstract method`.
