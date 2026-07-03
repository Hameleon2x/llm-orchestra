**Language:** **English** · [Русский](ru/04-tools.md)

# Tools (function calling)

A tool is a PHP class the model can invoke during an agent loop. This page covers the `ToolInterface` contract and the helper DTOs. Running tools inside the loop is in [05-toolbox-and-runner.md](05-toolbox-and-runner.md).

## When to write a tool

Write one when the model needs something outside its training data: a DB read, a remote API, a calculation against current state, a side effect. Tool execution is plain PHP — anything you can do in a method, you can wire as a tool.

## The contract

`Hameleon2x\Llm\Tool\ToolInterface`:

| Method                                | Returns          | Purpose                                                                                                       |
|---------------------------------------|------------------|---------------------------------------------------------------------------------------------------------------|
| `getName()`                           | `string`         | Function name sent to the model (e.g. `get_weather`). Must match `[a-zA-Z0-9_-]`.                            |
| `getDescription()`                    | `string`         | When/why the model should call this tool. In the `tools` list on every request.                              |
| `firstUseHint()`                      | `string`         | Note injected into the tool **result** (not the system prompt) under `firstUseHintKey()`, on the tool's first call in the dialogue. Explains the *output* shape, not the input. `''` for none (the default in `AbstractTool`). |
| `firstUseHintKey()`                   | `string`         | Key the note is stored under in the result. Default `hint_use` (`AbstractTool::DEFAULT_FIRST_USE_HINT_KEY`); override if it collides with a result field. |
| `getParameters()`                     | `Property[]`     | JSON Schema parameters, one `Property` per argument.                                                          |
| `execute(array $args)`                | `Tool\Dto\Result`| Run the tool; `$args` is the decoded JSON the model sent.                                                     |
| `shouldDisplay(array $args)`          | `bool`           | UI hint: should the chat surface this call (widget, preview)? Independent of execution.                       |

### `AbstractTool`

`Hameleon2x\Llm\Tool\AbstractTool` is a thin base that supplies defaults for `shouldDisplay(): bool = false`, `firstUseHint(): string = ''` and `firstUseHintKey(): string = 'hint_use'`. Everything else you implement yourself.

### Why `firstUseHint()`, not `getDescription()`?

`getDescription()` is in the `tools` array on every request and biases the model toward the tool ("use me"). Keep it short and call-focused.

`firstUseHint()` is injected into the tool's **result** — under the key `firstUseHintKey()` (default `hint_use`) — on the **first** call of that tool in the dialogue, by `Agent\Runner`. Use it to remind the model how to read the tool's own output — `temperatureC` is in Celsius, an empty `results` array means "nothing found", `status: closed` means the case is sealed — placed right next to the data it describes. It lands in the result, not the system prompt, so the system prompt stays a stable prefix and the provider's prompt cache isn't invalidated every turn. Returns `''` (the default in `AbstractTool`) when there is nothing to add; then no key is added to the result.

## `Property`

`Hameleon2x\Llm\Tool\Dto\Property` describes one JSON Schema property:

```php
new Property(
    string  $name,
    string|array $type,               // 'string', 'integer', 'number', 'boolean', 'array', 'object',
                                      // or a union like ['integer', 'null']
    ?string $description = null,
    bool    $required = false,
    ?array  $items = null             // for type='array': schema of element, e.g. ['type' => 'integer']
);
```

`Property[]` is fed to `Tool\SchemaBuilder::build()` (driven by the toolbox), producing `{ type: 'object', properties: { ... }, required: [...] }`.

## `Result`

`Hameleon2x\Llm\Tool\Dto\Result` is the return type for `execute()`:

```php
Result::ok(array $data = []);   // success — $data is a flat assoc array or list, serialised as-is
Result::error(string $message); // failure — wrapped as ['error' => $message] in the tool message
Result::suspend();              // pause — no result yet; supplied from outside (human-in-the-loop)
```

`Result::toJsonArray()` is called by the `Runner` to build the OpenAI `tool` message content. The wire format is intentionally simple — recent models are trained on the `{"error": "..."}` convention for failures, and bare JSON for successes.

`Result::suspend()` is a third outcome: the tool returns no data and asks the loop to pause until an external result (a user's answer, an approval) is supplied. See [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Worked example: `get_weather`

```php
<?php
declare(strict_types=1);

namespace App\Llm\Tools;

use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class GetWeatherTool extends AbstractTool
{
    public function getName(): string { return 'get_weather'; }

    public function getDescription(): string
    {
        return 'Get the current weather for a single city. Use when the user asks about weather, '
            . 'temperature, or conditions for a named place.';
    }

    public function firstUseHint(): string
    {
        return 'get_weather returns {city: string, temperatureC: number, condition: string}. '
            . '`condition` is one of: clear, cloudy, rain, snow, storm. `temperatureC` is in Celsius.';
    }

    public function getParameters(): array
    {
        return [
            new Property('city', 'string', 'City name in English, e.g. "Moscow"', true),
        ];
    }

    public function execute(array $args): Result
    {
        $city = trim((string)($args['city'] ?? ''));
        if ($city === '') {
            return Result::error('city is required');
        }
        // ... real implementation would hit a weather API here ...
        return Result::ok(['city' => $city, 'temperatureC' => 18, 'condition' => 'cloudy']);
    }

    public function shouldDisplay(array $args): bool { return true; }
}
```

What each method does in context:

- `getName()` — wired into OpenAI `function.name`. Don't change casually; dialog history references it.
- `getDescription()` — top-line "what and when". Mention the trigger explicitly so the model picks the tool on the right turns.
- `firstUseHint()` — output schema and edge cases. Injected into the tool result on first use, under `firstUseHintKey()` (default `hint_use`).
- `getParameters()` — inputs. Mark genuinely required parameters as `required = true`; the model uses this to decide whether it has enough info.
- `execute()` — validate `$args` defensively (the model can hallucinate). Return `Result::error(...)` on any failure — the error message goes back into the dialog and the model can recover.
- `shouldDisplay()` — UI hint only; orthogonal to execution.

## See also

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — how to register tools and run the loop.
- [06-events.md](06-events.md) — `TOOL_CALL` / `TOOL_RESULT` events while a tool runs.
- [../UPGRADING.md](../UPGRADING.md) — 0.1 → 0.2 migration (`getSystemPromptDescription` rename).
