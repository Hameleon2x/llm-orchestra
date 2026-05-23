**Язык:** [English](../07-history-serialization.md) · **Русский**

# Сериализация истории

Как передавать историю диалога между бэкендом и stateless-клиентом (браузерная форма, мобильное приложение, payload в очереди), не храня её в БД.

## Когда это нужно

[`Runner`](../../src/Agent/Runner.php) возвращает полную обновлённую историю в `Result::$messages`. Если вы не храните её на сервере, есть два варианта:

- сохранить историю в БД и загружать обратно по id разговора;
- отправить историю на фронт, хранить в скрытом поле и присылать обратно с каждым новым пользовательским сообщением.

Эта страница — про второй вариант: формат API и хелперы, конвертирующие `Message` ↔ обычные массивы.

## Формат API

[`MessageFactory::toArray()`](../../src/Factory/MessageFactory.php) выдаёт ассоциативный массив, ключи которого совпадают со схемой сообщения OpenAI Chat Completions — этот массив уходит провайдерам напрямую, без дополнительного маппинга.

| Ключ            | Когда присутствует                                           | Примечания                                                            |
|-----------------|--------------------------------------------------------------|-----------------------------------------------------------------------|
| `role`          | всегда                                                       | `system`, `user`, `assistant` или `tool` (см. [Role](../../src/Enum/Role.php)) |
| `content`       | когда не null                                                | текстовый payload; для сообщений `tool` — JSON-результат тулзы        |
| `name`          | когда не null                                                | опциональное имя говорящего                                           |
| `tool_calls`    | когда сообщение ассистента породило вызовы тулз              | массив, каждый элемент — `['id' => ..., 'type' => 'function', 'function' => ['name' => ..., 'arguments' => '...']]` |
| `tool_call_id`  | только в сообщениях `role: tool`                             | id вызова тулзы ассистента, на который отвечает этот результат        |

`MessageFactory::fromArray()` — обратное преобразование: толерантно к отсутствующим полям, игнорирует неизвестные ключи.

## Полный пример round-trip

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

Фронт сохраняет `response.history` в скрытое поле (или в `localStorage`) и присылает обратно без изменений со следующим сообщением.

## Сериализация отдельных вызовов тулз

Вызовы тулз ассистента лежат как простой массив внутри `tool_calls`. Чтобы работать с одним вызовом как с объектом — например, отрисовать его в UI — используйте [`ToolCallFactory`](../../src/Factory/ToolCallFactory.php):

```php
use Hameleon2x\Llm\Factory\ToolCallFactory;

$array  = ToolCallFactory::toArray($toolCallObject);
$object = ToolCallFactory::fromArray($array);
```

`ToolCall::getArguments()` декодирует JSON-строку `arguments` в ассоциативный массив — удобно, когда вы сохраняете вызовы тулз для аналитики.

## Сериализация определений тулз

Если вы передаёте определения тулз по сети (например, воркеру, который зовёт `Client::execute()` напрямую), используйте [`ToolDefinitionFactory::toArray()`](../../src/Factory/ToolDefinitionFactory.php): `$payload = array_map([ToolDefinitionFactory::class, 'toArray'], $tools);`. `fromArray()` нет — определения тулз создаются вашими реализациями `ToolInterface`, а не восстанавливаются из недоверенного ввода. Собирайте их на стороне сервера.

Примечания: не включайте системное сообщение в передаваемую историю — `Runner` пересобирает его каждый ход через callable `$systemPromptFn`. Формат намеренно OpenAI-совместимый: тот же массив можно отправить любому OpenAI-совместимому API.

## См. также

- [docs/05-toolbox-and-runner.md](05-toolbox-and-runner.md) — как `Runner` потребляет и обновляет историю.
- [docs/08-config-reference.md](08-config-reference.md) — runtime-параметры, которым не место в передаваемой истории.
- [docs/architecture.md](architecture.md) — где `MessageFactory` находится в стеке.
