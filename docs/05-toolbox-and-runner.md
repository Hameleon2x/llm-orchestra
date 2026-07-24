**Language:** **English** · [Русский](ru/05-toolbox-and-runner.md)

# Tools and the agent loop

A regular request is "asked → got text." The agent loop is needed when the model must first **go fetch data**: check the weather, look up a client in the database, compute something. Then the conversation goes like this:

1. We send the model a question and a list of available tools.
2. The model responds not with text but with a request: "call `get_weather` with argument `city = Moscow`".
3. We execute the tool in our own code and send the result back.
4. The model either asks for something else or answers the user.

`Agent\Runner` drives these four steps. It takes tools from the **toolbox** — a registry that you describe yourself. How to write a single tool is covered in [04-tools.md](04-tools.md); this page covers how to assemble them together and run the loop.

## Full example

Copy it whole, plug in your token — it will work. The tool here is the simplest possible, so it doesn't distract.

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Agent\AbstractToolbox;
use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;
use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result as ToolResult;

// 1. Tool: what the model will be able to call.
final class GetWeatherTool extends AbstractTool
{
    public function getName(): string
    {
        return 'get_weather';
    }

    public function getDescription(): string
    {
        return 'Current weather in a city. Call this when the user asks about the weather.';
    }

    public function getParameters(): array
    {
        return [new Property('city', 'string', 'City name, e.g. "Moscow"', true)];
    }

    public function execute(array $args): ToolResult
    {
        // A call to a weather API would go here.
        return ToolResult::ok(['city' => $args['city'] ?? '', 'temp' => 7, 'text' => 'cloudy']);
    }
}

// 2. Toolbox: the registry of tools for this run.
final class WeatherToolbox extends AbstractToolbox
{
    protected function buildTools(): array
    {
        return [new GetWeatherTool()];
    }
}

// 3. Model catalog and runner — as in 01-getting-started.
$orchestra = new Orchestra(Registry::fromArray([
    'providers' => ['openai' => ['class' => OpenAiProvider::class, 'token' => 'sk-...']],
    'models'    => ['mini'   => ['provider' => 'openai', 'name' => 'gpt-4o-mini']],
    'defaultModel' => 'mini',
]));

// 4. Run settings.
$config = new Config();
$config->model = 'mini';
$config->maxTurns = 12;
$config->maxToolCalls = 10;
$config->params->temperature = 0.3;

// 5. Run it.
$result = (new Runner($orchestra))->run(
    [Message::user('What is the weather in Moscow right now?')],
    new WeatherToolbox(),
    static fn(): string => 'You answer weather questions concisely. Get facts from the tools.',
    $config
);

echo $result->success ? $result->content : 'Failure: ' . $result->error->category;
```

The model will decide on its own that it needs `get_weather` to answer, call it, get back `{"city":"Moscow","temp":7,"text":"cloudy"}`, and formulate an answer for the user.

## How to read the result

`Runner::run()` returns `Agent\Dto\Result`. Useful fields:

- **`$success`** — the run reached an answer. `false` on a model call failure, on run timeout, and on a pause for external input.
- **`$content`** — the final text for the user; `null` when `$success` is `false`.
- **`$error`** — `Error\ErrorInfo` with the failure category, if there was one. You don't need to parse the error text, see [10-error-handling.md](10-error-handling.md).
- **`$finish`** — why the loop stopped: `Finish::COMPLETED`, `TOOL_LIMIT`, `TURNS_EXHAUSTED`, `DEADLINE`, `ERROR`, or `SUSPENDED`.
- **`$messages`** — the full history after the run (without the system message). Save it if the conversation continues.
- **`$turnsUsed`, `$toolCallsUsed`** — how many turns and tool calls were spent.
- **`$usage`** — tokens, cost, and a per-model breakdown, see [09-usage-and-limits.md](09-usage-and-limits.md).
- **`$modelKey`** — which model worked last. Differs from the requested one if a switch to a fallback happened on failure.
- **`$attempts`** — the log of model call attempts: retries and switches.
- **`$lastResponse`** — the last model response in full: reasoning in `extra`, the raw response through `raw()`.
- **`$suspended`, `$pendingToolCallIds`** — the run has paused and is waiting for external input, see [13-human-in-the-loop.md](13-human-in-the-loop.md).

## Toolbox

The toolbox is a class that gives the loop a list of tools and can execute any of them by name. The simplest approach is to subclass `AbstractToolbox` and implement one method:

```php
<?php
use Hameleon2x\Llm\Agent\AbstractToolbox;

final class MyToolbox extends AbstractToolbox
{
    protected function buildTools(): array
    {
        return [
            new GetWeatherTool($this->httpClient),   // a convenient place to pass in your own services
            new FindClientTool($this->clientRepository),
        ];
    }
}
```

`buildTools()` is called once, lazily — this is where tools get their dependencies: repositories, HTTP clients, the current user.

If your project assembles tools differently (for example, reads them from a database), implement `ToolboxInterface` directly — `Runner` works with any implementation.

### An explanation of the call for the UI: `log_message`

Often you want the UI to show not "get_weather was called" but a human-readable string like "Checking the weather in Moscow…". To have the model itself write such a string, enable `log_message` in the toolbox:

```php
final class MyToolbox extends AbstractToolbox
{
    protected bool    $withLogMessage        = true;
    protected ?string $logMessageDescription = 'A short note: what you are doing with this call and why.';

    protected function buildTools(): array { /* ... */ }
}
```

Then every tool's schema gains a required string parameter `log_message`, which arrives along with the other arguments — the tool can read it or ignore it, and it reaches the UI through the `TOOL_CALL` event ([06-events.md](06-events.md)).

## The `run()` signature

```php
public function run(
    array            $messages,        // Message[] — history without the system message
    ToolboxInterface $toolbox,
    callable         $systemPromptFn,  // fn(Message[] $history): string
    Config           $config,
    ?callable        $emit = null      // fn(string $event, string $content, array $meta): void
): Result
```

- **`$messages`** — the dialog history. The system message doesn't go here: the loop adds it itself.
- **`$systemPromptFn`** — a function returning the system prompt. Called every turn and receives the current history, so the prompt can be built dynamically. The returned text goes to the model as-is.
- **`$config`** — run settings: model, limits, generation parameters. Full breakdown — [08-config-reference.md](08-config-reference.md).
- **`$emit`** — an optional event sink: progress in the UI, logging the dialog to a database ([06-events.md](06-events.md)).

## Limits

Two limits guard against endless work:

- **`maxTurns`** — how many times the model can be called. One turn is one request, even if the model asked for five tools at once in it.
- **`maxToolCalls`** — how many tools can be executed over the whole run.

What happens when they run out:

- **`maxToolCalls` exhausted.** The remaining calls of this turn are closed with an error, the message `limitNudgeMessage` ("no more data is coming, give a final answer") is added to the history, and one more request is made — this time without tools. The model's answer becomes the result; if it returns a turn with no text, that is an `empty_response` failure — `limitFallbackText` is left for the rare case when the answer holds only unrequested tool calls. This request goes beyond the turn budget and does not increase `turnsUsed`. In both cases `$success` is `true` and `$finish` is `Finish::TOOL_LIMIT`. If that request itself fails (network, context length, unavailability), the run returns an error with a category, just like on any turn — no placeholder is substituted for it.
- **`maxTurns` exhausted.** `turnsExhaustedText` is appended to the history and also lands in `$content`. `$success` is `true`, `$finish` is `Finish::TURNS_EXHAUSTED`.

Both cases are not an error but a normal completion within budget. `$finish` helps you tell them apart from a full answer.

The third limiter is the deadline: `$config->deadlineSeconds`. It is checked before every turn, and on expiry the run returns an error of category `deadline` along with the full history: the tool results collected so far are not lost. The check sits at the start of a turn, and the remaining time is passed to the executor as the wait cap for the call — so retries and switches inside a turn are bounded too. Only finishing off unanswered calls on resume happens outside it.

## Hint on a tool's first call

A tool can have a non-obvious response format — for example, fields `docId` and `sources[]` that the model must use in a specific way. Such an explanation shouldn't live in the system prompt: it's sent to the model on every request and costs tokens even when the tool isn't used.

Instead, the loop mixes the explanation into the tool's result on its **first** call in the dialog: `$toolbox->firstUseHint($name)` is placed into the JSON response under the key `$toolbox->firstUseHintKey($name)` (default `hint_use`). Once per dialog, at the tail of the history — the start of the request stays unchanged, and the provider's prompt cache keeps working. The note goes in under its own key. A tool that answers with a list has nowhere to put that key, so on the first call its list is tucked under `Config::$firstUseResultKey` (`result` by default) with the note next to it: `{"hint_use": "…", "result": [...]}`. Later calls return a plain list again.

## See also

- [04-tools.md](04-tools.md) — how to write a tool.
- [06-events.md](06-events.md) — loop events: progress, retries, model switching.
- [08-config-reference.md](08-config-reference.md) — all run settings.
- [13-human-in-the-loop.md](13-human-in-the-loop.md) — pausing the loop for a user's answer.
- [02-catalog-and-fallback.md](02-catalog-and-fallback.md) — how a model is chosen and what happens on its failure.
