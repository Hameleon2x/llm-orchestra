[![en](https://img.shields.io/badge/lang-en-red.svg)](UPGRADING.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](UPGRADING.ru.md)

# Upgrading

Ломающие изменения между мажорными версиями. Описания релизов: [CHANGELOG.ru.md](CHANGELOG.ru.md).

## 0.1.x → 0.2.x

`ToolInterface::getSystemPromptDescription()` переименован в `ToolInterface::appendToSystemPromptAfterUse()`. Сигнатура и семантика не изменились.

Переименование по всему проекту:

```
getSystemPromptDescription  →  appendToSystemPromptAfterUse
```

Каждый класс, реализующий `ToolInterface` (или наследующий `AbstractTool`), обновляешь сам — иначе PHP бросит `Fatal error: ... contains 1 abstract method`.
