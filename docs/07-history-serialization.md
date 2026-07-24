**Language:** **English** · [Русский](ru/07-history-serialization.md)

# History serialization

How to pass a dialog history between the backend and a stateless client (a browser form, a mobile app, a queue payload) without storing it in the database.

## When you need this

[`Runner`](../src/Agent/Runner.php) returns the full updated history in `Result::$messages`. If you don't store it on the server, there are two options:

- save the history to the database and load it back by conversation id;
- send the history to the frontend, store it in a hidden field, and post it back with every new user message.

This page is about the second option: the wire format and the helpers that convert `Message` ↔ plain arrays.

## Wire format

[`MessageFactory::toArray()`](../src/Factory/MessageFactory.php) produces an associative array whose keys match the OpenAI Chat Completions message schema — this array goes straight to providers without extra mapping.

Array keys:

- **`role`** — always present: `system`, `user`, `assistant`, or `tool` (see [Role](../src/Enum/Role.php)).
- **`content`** — the message text, when present. For `tool`-role messages this is the JSON result of the tool.
- **`name`** — an optional speaker name, if set.
- **`tool_calls`** — appears in an assistant message that requested tool calls. Each item: `['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '...']]`.
- **`tool_call_id`** — only in `tool`-role messages: the id of the call this result answers.

`MessageFactory::fromArray()` is the reverse conversion: tolerant of missing fields, ignores unknown keys.

## Round-trip example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Factory\MessageFactory;

/** @var Orchestra $orchestra */
/** @var \Hameleon2x\Llm\Agent\ToolboxInterface $toolbox */
/** @var \Hameleon2x\Llm\Agent\Dto\RunOptions $config */

// 1. Restore history sent from the frontend.
$rawHistory = json_decode($_POST['history'] ?? '[]', true) ?: [];
$messages   = array_map([MessageFactory::class, 'fromArray'], $rawHistory);

// 2. Append the new user message.
$messages[] = Message::user($_POST['text']);

// 3. Run the agent.
$runner = new Runner($orchestra);
$result = $runner->run($messages, $toolbox, fn() => 'You are a helpful assistant', $config);

// 4. Send the new history back.
echo json_encode([
    'answer'  => $result->content,
    'history' => array_map([MessageFactory::class, 'toArray'], $result->messages),
], JSON_UNESCAPED_UNICODE);
```

The frontend saves `response.history` in a hidden field (or in `localStorage`) and posts it back unchanged with the next message.

## Serializing individual tool calls

The assistant's tool calls sit as a plain array inside `tool_calls`. To work with a single call as an object — for example, to render it in the UI — use [`ToolCallFactory`](../src/Factory/ToolCallFactory.php):

```php
use Hameleon2x\Llm\Factory\ToolCallFactory;

$array  = ToolCallFactory::toArray($toolCallObject);
$object = ToolCallFactory::fromArray($array);
```

`ToolCall::getArguments()` decodes the JSON string `arguments` into an associative array — handy when you persist tool calls for analytics.

## Serializing tool definitions

If you pass tool definitions over the network (for example, to a worker that calls `Orchestra::execute()` directly), use [`ToolDefinitionFactory::toArray()`](../src/Factory/ToolDefinitionFactory.php): `$payload = array_map([ToolDefinitionFactory::class, 'toArray'], $tools);`. There is no `fromArray()` — tool definitions are created by your `ToolInterface` implementations, not restored from untrusted input. Build them on the server side.

Notes: don't include the system message in the transmitted history — `Runner` rebuilds it every turn through the `$systemPromptFn` callable. The format is intentionally OpenAI-compatible: the same array can be sent to any OpenAI-compatible API.

## See also

- [05-toolbox-and-runner.md](05-toolbox-and-runner.md) — how `Runner` consumes and updates the history.
- [08-config-reference.md](08-config-reference.md) — run parameters that don't belong in the transmitted history.
- [architecture.md](architecture.md) — where `MessageFactory` sits in the stack.
