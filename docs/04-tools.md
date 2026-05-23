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
| `appendToSystemPromptAfterUse()`      | `string`         | Notes appended to the **system** prompt only after the tool has been called at least once. Explains the *output* shape, not the input. `''` for none. |
| `getParameters()`                     | `Property[]`     | JSON Schema parameters, one `Property` per argument.                                                          |
| `execute(array $args)`                | `Tool\Dto\Result`| Run the tool; `$args` is the decoded JSON the model sent.                                                     |
| `shouldDisplay(array $args)`          | `bool`           | UI hint: should the chat surface this call (widget, preview)? Independent of execution.                       |

### `AbstractTool`

`Hameleon2x\Llm\Tool\AbstractTool` is a thin base that supplies one default: `shouldDisplay(): bool = false`. Everything else you implement yourself.

### Why `appendToSystemPromptAfterUse()`, not `getDescription()`?

`getDescription()` is in the `tools` array on every request and biases the model toward the tool ("use me"). Keep it short and call-focused.

`appendToSystemPromptAfterUse()` is appended to the **system** prompt only on turns where the tool has already appeared in the dialog history (driven by `Agent\SystemPromptComposer`). Use it to remind the model how to read its own output — `temperatureC` is in Celsius, an empty `results` array means "nothing found", `status: closed` means the case is sealed — so subsequent turns reason about the result correctly without spending tokens on every turn beforehand.

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
```

`Result::toJsonArray()` is called by the `Runner` to build the OpenAI `tool` message content. The wire format is intentionally simple — recent models are trained on the `{"error": "..."}` convention for failures, and bare JSON for successes.

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

    public function appendToSystemPromptAfterUse(): string
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
- `appendToSystemPromptAfterUse()` — output schema and edge cases. Lives in the system prompt from the first use onward.
- `getParameters()` — inputs. Mark genuinely required parameters as `required = true`; the model uses this to decide whether it has enough info.
- `execute()` — validate `$args` defensively (the model can hallucinate). Return `Result::error(...)` on any failure — the error message goes back into the dialog and the model can recover.
- `shouldDisplay()` — UI hint only; orthogonal to execution.

## See also

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — how to register tools and run the loop.
- [06-events.md](06-events.md) — `TOOL_CALL` / `TOOL_RESULT` events while a tool runs.
- [../UPGRADING.md](../UPGRADING.md) — 0.1 → 0.2 migration (`getSystemPromptDescription` rename).
