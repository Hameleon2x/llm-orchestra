**Language:** **English** · [Русский](ru/07-history-serialization.md)

# History serialization

How to move a dialog history between the backend and a stateless client (browser form, mobile app, queue payload) without storing it in your database.

## When you need this

[`Runner`](../src/Agent/Runner.php) returns the full updated history in `Result::$messages`. If you don't keep it on the server, two options:

- save the history to the DB and load it back by conversation id;
- send the history to the frontend, store it in a hidden field, post it back with every new user message.

This page covers the second option: the wire format and helpers that convert `Message` ↔ plain arrays.

## Wire format

[`MessageFactory::toArray()`](../src/Factory/MessageFactory.php) produces an associative array whose keys match the OpenAI Chat Completions message schema — the same array goes straight to providers without extra mapping.

| Key             | When present                                                 | Notes                                                                 |
|-----------------|--------------------------------------------------------------|-----------------------------------------------------------------------|
| `role`          | always                                                       | `system`, `user`, `assistant`, or `tool` (see [Role](../src/Enum/Role.php)) |
| `content`       | when not null                                                | text payload; for `tool` messages — JSON-encoded tool result          |
| `name`          | when not null                                                | optional speaker name                                                 |
| `tool_calls`    | when an assistant message produced tool calls                | array, each item is `['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '...']]` |
| `tool_call_id`  | only on `role: tool` messages                                | id of the assistant tool call this result answers                     |

`MessageFactory::fromArray()` is the inverse: tolerates missing fields, ignores unknown keys.

## Round-trip example

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Factory\MessageFactory;

/** @var Client $client */
/** @var \Hameleon2x\Llm\Agent\ToolboxInterface $toolbox */
/** @var \Hameleon2x\Llm\Agent\Dto\Config $config */

// 1. Restore history sent from the frontend.
$rawHistory = json_decode($_POST['history'] ?? '[]', true) ?: [];
$messages   = array_map([MessageFactory::class, 'fromArray'], $rawHistory);

// 2. Append the new user message.
$messages[] = Message::user($_POST['text']);

// 3. Run the agent.
$runner = new Runner($client);
$result = $runner->run($messages, $toolbox, fn() => 'You are a helpful assistant', $config);

// 4. Send the new history back.
echo json_encode([
    'answer'  => $result->content,
    'history' => array_map([MessageFactory::class, 'toArray'], $result->messages),
], JSON_UNESCAPED_UNICODE);
```

The frontend stores `response.history` in a hidden field (or in `localStorage`) and posts it back unchanged with the next message.

## Serializing individual tool calls

The assistant's tool calls are stored as a plain array inside `tool_calls`. To work with a single tool call as an object — e.g. to render it in the UI — use [`ToolCallFactory`](../src/Factory/ToolCallFactory.php):

```php
use Hameleon2x\Llm\Factory\ToolCallFactory;

$array  = ToolCallFactory::toArray($toolCallObject);
$object = ToolCallFactory::fromArray($array);
```

`ToolCall::getArguments()` decodes the JSON-encoded `arguments` string into an associative array — handy when you persist tool calls for analytics.

## Serializing tool definitions

If you ship tool definitions over the wire (e.g. to a worker that calls `Client::execute()` directly), use [`ToolDefinitionFactory::toArray()`](../src/Factory/ToolDefinitionFactory.php): `$payload = array_map([ToolDefinitionFactory::class, 'toArray'], $tools);`. There is no `fromArray()` — tool definitions are produced by your `ToolInterface` implementations, not rebuilt from untrusted input. Build them on the server side.

Notes: do not include the system message in the wire history — `Runner` rebuilds it every turn via the `$systemPromptFn` callable. The format is OpenAI-shaped on purpose: the same array can be sent to any OpenAI-compatible API.

## See also

- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — how `Runner` consumes and updates the history.
- [docs/08-config-reference.md](08-config-reference.md) — runtime parameters that do not belong on the wire.
- [docs/architecture.md](architecture.md) — where `MessageFactory` sits in the stack.
