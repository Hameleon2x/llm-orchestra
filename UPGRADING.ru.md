[![en](https://img.shields.io/badge/lang-en-red.svg)](UPGRADING.md)
[![ru](https://img.shields.io/badge/lang-ru-blue.svg)](UPGRADING.ru.md)

# Upgrading

Ломающие изменения между мажорными версиями. Описания релизов: [CHANGELOG.ru.md](CHANGELOG.ru.md).

## 0.2.x → 0.3.x

Пояснения по тулзам больше не дописываются в системный промт — `Runner` подмешивает их в РЕЗУЛЬТАТ тулзы при первом вызове (стабильный системный префикс → живой prompt-кеш провайдера). Переименованы методы, удалён `SystemPromptComposer`.

Переименование в твоём коде:

```
ToolInterface::appendToSystemPromptAfterUse()  →  ToolInterface::firstUseHint()
ToolboxInterface::systemPromptAddition($name)  →  ToolboxInterface::firstUseHint($name)
```

- Каждый класс, реализующий `ToolInterface` (или наследующий `AbstractTool`), обнови — иначе `Fatal error: ... contains N abstract methods`. Тулзе без пояснения метод можно вовсе убрать: `AbstractTool::firstUseHint()` теперь возвращает `''` по умолчанию.
- Своя реализация `ToolboxInterface` (не через `AbstractToolbox`): переименуй `systemPromptAddition()` в `firstUseHint()` и добавь `firstUseHintKey(string $name): string` (верни `AbstractTool::DEFAULT_FIRST_USE_HINT_KEY`, если ключ не важен).
- `Agent\SystemPromptComposer` удалён. Если ты им пользовался (например, показать «полный» системный промт в UI) — показывай просто базовый промт из `$systemPromptFn`; пояснения по тулзам теперь лежат в результатах тулз под ключом `firstUseHintKey()` (дефолт `hint_use`).
- Опционально: если дефолтный ключ `hint_use` конфликтует с полем результата тулзы — переопредели `firstUseHintKey()` в этой тулзе.

## 0.1.x → 0.2.x

`ToolInterface::getSystemPromptDescription()` переименован в `ToolInterface::appendToSystemPromptAfterUse()`. Сигнатура и семантика не изменились.

Переименование по всему проекту:

```
getSystemPromptDescription  →  appendToSystemPromptAfterUse
```

Каждый класс, реализующий `ToolInterface` (или наследующий `AbstractTool`), обновляешь сам — иначе PHP бросит `Fatal error: ... contains 1 abstract method`.
