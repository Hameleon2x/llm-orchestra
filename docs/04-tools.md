**Language:** **English** · [Русский](ru/04-tools.md)

# Tools (function calling)

A tool is a PHP class the model can call on its own. The model doesn't execute your code: it sees the tool's name, its description, and the list of arguments, and when it decides the tool is needed, it sends back the name and the arguments. Your code then calls the tool, and the result goes back into the dialogue.

Tools are needed where the model cannot know the answer: data from a database, a call to an external API, a calculation based on current state, an action with a side effect.

This page is about a single tool. How to assemble them into a registry and run the loop — [05-toolbox-and-runner.md](05-toolbox-and-runner.md).

## Minimal tool

```php
<?php

namespace App\Llm\Tools;

use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class TimeNowTool extends AbstractTool
{
    public function getName(): string
    {
        return 'time_now';
    }

    public function getDescription(): string
    {
        return 'The current date and time on the server. Call when you need "now".';
    }

    public function getParameters(): array
    {
        return [];   // no arguments
    }

    public function execute(array $args): Result
    {
        return Result::ok(['iso' => date('c')]);
    }
}
```

Four methods, and the model can already tell the time. `AbstractTool` covers the rest of the contract's methods with sensible defaults.

## Tool with arguments

```php
<?php

namespace App\Llm\Tools;

use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

final class GetWeatherTool extends AbstractTool
{
    private WeatherApi $api;

    public function __construct(WeatherApi $api)
    {
        $this->api = $api;
    }

    public function getName(): string
    {
        return 'get_weather';
    }

    public function getDescription(): string
    {
        return 'The current weather for a single city. Call when asked about the weather, '
            . 'temperature, or conditions in a specific place.';
    }

    public function getParameters(): array
    {
        return [
            new Property('city', 'string', 'City name, e.g. "Moscow"', true),
        ];
    }

    public function firstUseHint(): string
    {
        return 'get_weather returns {city, temperatureC, condition}. '
            . 'condition is one of: clear, cloudy, rain, snow, storm. temperatureC is in Celsius.';
    }

    public function execute(array $args): Result
    {
        $city = trim((string)($args['city'] ?? ''));
        if ($city === '') {
            return Result::error('City not specified (city).');
        }

        $weather = $this->api->current($city);

        return Result::ok([
            'city'         => $city,
            'temperatureC' => $weather->temp,
            'condition'    => $weather->condition,
        ]);
    }

    public function shouldDisplay(array $args): bool
    {
        return true;
    }
}
```

The tool receives its dependencies through the constructor — the toolbox supplies them (see [05-toolbox-and-runner.md](05-toolbox-and-runner.md)).

## What the contract consists of

`Hameleon2x\Llm\Tool\ToolInterface`:

- **`getName(): string`** — the function name for the model: `get_weather`. Only letters, digits, `_`, and `-`. Don't change it on a working tool: saved dialogue history refers to this name.
- **`getDescription(): string`** — when and why to call it. Goes into every request together with the list of tools, so keep it short and with an explicit trigger: "call when asked about the weather".
- **`getParameters(): array`** — a list of `Property`, one per argument. A JSON Schema is built from them, and the model shapes its call against it.
- **`execute(array $args): Result`** — the actual work. `$args` is the already-decoded JSON from the model.
- **`firstUseHint(): string`** — an explanation of how to read the tool's **response**. Mixed into its result on the first call in the dialogue. Empty by default. An object result gets the note as a neighbouring key, while a list result is tucked under `RunOptions::$firstUseResultKey` (`result` by default) next to the note: a list cannot take a key, and losing the note would be worse.
- **`firstUseHintKey(): string`** — the key the explanation is placed under in the result. `hint_use` by default; change it if that key is already used by your data.
- **`shouldDisplay(array $args): bool`** — a hint for the UI: whether to show this call to the user. Doesn't affect execution.

`AbstractTool` implements the last three methods with default values (`''`, `hint_use`, `false`), so a simple tool only needs to implement the first four.

## Description versus hint

The two texts solve different problems, and you shouldn't confuse them.

`getDescription()` answers the question "when to call me" and goes to the model on **every** request — this costs tokens on every turn of the dialogue. Keep it to one or two lines.

`firstUseHint()` answers the question "how to read my response": what `condition: storm` means, that an empty `results` array means "nothing found", what unit the temperature is in. It's mixed directly into the result on the first call of the tool, so it's paid for once and only if the tool was actually needed. At the same time, the system prompt stays unchanged between turns, and the provider's prompt cache keeps working.

## Arguments: `Property`

```php
new Property(
    string       $name,
    string|array $type,               // 'string', 'integer', 'number', 'boolean', 'array', 'object'
                                      // or a union: ['integer', 'null']
    ?string      $description = null,
    bool         $required = false,
    ?array       $items = null         // for type = 'array': schema of the element, ['type' => 'integer']
);
```

Examples:

```php
new Property('city', 'string', 'City name', true);
new Property('limit', 'integer', 'How many records to return, 10 by default');
new Property('ids', 'array', 'Task identifiers', true, ['type' => 'integer']);
new Property('comment', ['string', 'null'], 'Comment, if any');
```

Mark required arguments with `required = true` honestly: the model uses this flag to tell whether it has enough data to make the call.

## Result: `Result`

`execute()` has three possible outcomes:

```php
Result::ok(['city' => 'Moscow', 'temperatureC' => 7]);   // success: the data goes to the model as JSON
Result::error('City not specified (city).');              // error: the model will see {"error": "..."}
Result::suspend();                                        // pause: the result will arrive from outside
```

A tool error is not an exception. Return `Result::error()` with text that makes it clear to the model what to fix: it will see it on the next turn and will be able to ask again or call the tool differently.

If an exception does escape `execute()`, the loop catches it, closes the call with a neutral error and logs the details (`LLM tool threw an exception`). By default the exception message is not shown to the model: it is written for a developer, it can be huge and carry internals (a `PDOException` message holds the full SQL with parameter values) — and the history goes to the provider and is repeated on every following turn.

If your tools throw exceptions that are meaningful to the model, set `$options->exposeToolExceptions = true`: the message reaches the model as a single line, trimmed to 300 characters.

`Result::suspend()` stops the loop and waits for external input — for example, the user's answer to a clarifying question. Details: [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Arguments come from the model, not from you

The model can get a type wrong, skip a required field, or make up a value. Validate `$args` the same way you would validate input from an external request:

```php
public function execute(array $args): Result
{
    $limit = (int)($args['limit'] ?? 10);
    if ($limit < 1 || $limit > 100) {
        return Result::error('limit must be between 1 and 100.');
    }
    // ...
}
```

The loop guards against one class of errors on its own: if the model sends arguments with leaked call markup (`<parameter name="...">` inside a value), the tool won't be executed — the model gets an error back and resends the call. This is `RunOptions::$toolArgsGuard`, enabled by default.

## See also

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — the tool registry and running the loop.
- [06-events.md](06-events.md) — `TOOL_CALL` and `TOOL_RESULT` events while a tool runs.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — a tool that waits for the user's answer.
