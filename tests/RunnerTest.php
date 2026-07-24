<?php

/** Агентский цикл: инструменты, лимиты, срок, события, приостановка и возобновление. */

use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Enum\Event;
use Hameleon2x\Llm\Agent\Enum\Finish;
use Hameleon2x\Llm\Agent\Runner;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Enum\Role;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Orchestra;

/** Прогон на каталоге из одной модели. */
function runWith(FakeChatClient $client, array $tools, ?Config $config = null, array $messages = [], ?callable $emit = null, array $catalogExtra = [])
{
    $runner = new Runner(new Orchestra(catalogOf($client, $catalogExtra), null, new RecordingSleeper()));

    return $runner->run(
        $messages !== [] ? $messages : [Message::user('вопрос')],
        new ToolBox($tools),
        systemPrompt(),
        $config ?? new Config(),
        $emit
    );
}

/** Содержимое tool-сообщений результата, по порядку. */
function toolMessages($result): array
{
    $contents = [];
    foreach ($result->messages as $message) {
        if ($message->role === Role::TOOL) {
            $contents[] = (string)$message->content;
        }
    }

    return $contents;
}

suite('Цикл: базовый ход');

test('ответ без инструментов завершает прогон', static function (): void {
    $result = runWith(new FakeChatClient([okBody('  готово  ')]), []);

    assertTrue($result->success);
    assertSame(Finish::COMPLETED, $result->finish);
    assertSame('готово', $result->content, 'текст ответа обрезается по краям');
    assertSame(1, $result->turnsUsed);
    assertSame('m', $result->modelKey);
});

test('вызов инструмента исполняется, результат уходит модели', static function (): void {
    $tool = new EchoTool();
    $client = new FakeChatClient([toolBody('c1', 'echo_tool', ['text' => 'привет']), okBody('итог')]);

    $result = runWith($client, [$tool]);

    assertSame(1, $tool->calls);
    assertSame(1, $result->toolCallsUsed);
    assertSame(2, $result->turnsUsed);
    assertSame('итог', $result->content);
    assertContains('привет', toolMessages($result)[0]);
});

test('пояснение первого вызова подмешивается один раз', static function (): void {
    $client = new FakeChatClient([
        toolBody('c1', 'echo_tool', ['text' => 'раз']),
        toolBody('c2', 'echo_tool', ['text' => 'два']),
        okBody('итог'),
    ]);

    $messages = toolMessages(runWith($client, [new EchoTool()]));

    assertContains('hint_use', $messages[0]);
    assertFalse(strpos($messages[1], 'hint_use') !== false, 'на втором вызове пояснения уже нет');
});

test('результат-список получает пояснение через свой ключ', static function (): void {
    $client = new FakeChatClient([
        toolBody('c1', 'list_tool'),
        toolBody('c2', 'list_tool'),
        okBody('итог'),
    ]);

    $messages = toolMessages(runWith($client, [new ListTool()]));
    $first = json_decode($messages[0], true);

    assertSame('Список отсортирован по релевантности.', $first['hint_use']);
    assertSame(['первый', 'второй'], $first['result'], 'список сохранён целиком');
    assertSame(['первый', 'второй'], json_decode($messages[1], true), 'дальше — обычный список');
});

suite('Цикл: сбои и лимиты');

test('исключение инструмента не роняет прогон и не показывает своих внутренностей', static function (): void {
    $result = runWith(new FakeChatClient([toolBody('c1', 'boom'), okBody('продолжаю')]), [new BoomTool()]);

    assertTrue($result->success);
    assertFalse(strpos(toolMessages($result)[0], 'SQLSTATE') !== false, 'текст исключения модели не уходит');
});

test('exposeToolExceptions показывает сообщение модели', static function (): void {
    $config = new Config();
    $config->exposeToolExceptions = true;

    $result = runWith(new FakeChatClient([toolBody('c1', 'boom'), okBody('ок')]), [new BoomTool()], $config);

    assertContains('SQLSTATE', toolMessages($result)[0]);
});

test('тексты для модели берутся из конфига', static function (): void {
    $config = new Config();
    $config->toolFailedText = 'инструмент сломан';

    $result = runWith(new FakeChatClient([toolBody('c1', 'boom'), okBody('ок')]), [new BoomTool()], $config);

    assertSame('{"error":"инструмент сломан"}', toolMessages($result)[0]);
});

test('исчерпанный лимит вызовов заканчивается добивкой без инструментов', static function (): void {
    $config = new Config();
    $config->maxToolCalls = 1;

    $client = new FakeChatClient([
        toolBody('c1', 'echo_tool', ['text' => 'раз']),
        toolBody('c2', 'echo_tool', ['text' => 'два']),
        okBody('итог после добивки'),
    ]);

    $result = runWith($client, [new EchoTool()], $config);

    assertSame(Finish::TOOL_LIMIT, $result->finish);
    assertSame('итог после добивки', $result->content);
    assertFalse(isset($client->lastPayload()['tools']), 'в запросе-добивке инструментов нет');
});

test('исчерпанный лимит оборотов возвращает заглушку', static function (): void {
    $config = new Config();
    $config->maxTurns = 1;

    $result = runWith(new FakeChatClient([toolBody('c1', 'echo_tool')]), [new EchoTool()], $config);

    assertSame(Finish::TURNS_EXHAUSTED, $result->finish);
    assertSame($config->turnsExhaustedText, $result->content);
});

test('сбой модели возвращает ошибку с историей и упавшей моделью', static function (): void {
    $client = new FakeChatClient([new RuntimeException('down', 500)]);

    $result = runWith($client, [], null, [], null, ['defaultPolicy' => ['retries' => 0]]);

    assertFalse($result->success);
    assertSame(Finish::ERROR, $result->finish);
    assertSame(ErrorCategory::SERVER_ERROR, $result->error->category);
    assertSame('m', $result->modelKey);
    assertCount(1, $result->messages, 'история прогона сохранена');
});

test('сбой прикладного кода возвращается как ошибка конфигурации', static function (): void {
    $runner = new Runner(new Orchestra(catalogOf(new FakeChatClient([okBody('не дойдёт')])), null, new RecordingSleeper()));

    $result = $runner->run(
        [Message::user('вопрос')],
        new ToolBox([]),
        static function (array $history): string {
            throw new RuntimeException('промт не собрался');
        },
        new Config()
    );

    assertFalse($result->success);
    assertSame(ErrorCategory::CONFIG, $result->error->category);
    assertSame(0, $result->turnsUsed, 'оборот не состоялся');
});

test('сбой приёмника событий не обрывает прогон', static function (): void {
    $result = runWith(
        new FakeChatClient([toolBody('c1', 'echo_tool'), okBody('ок')]),
        [new EchoTool()],
        null,
        [],
        static function (string $event, string $content, array $meta): void {
            throw new RuntimeException('интерфейс упал');
        }
    );

    assertTrue($result->success);
});

suite('Цикл: срок прогона');

test('срок берётся из конфига прогона', static function (): void {
    $config = new Config();
    $config->deadlineSeconds = 0.3;
    $client = new FakeChatClient([okBody('не успеет')]);

    $result = runWith($client, [], $config);

    assertSame(Finish::DEADLINE, $result->finish);
    assertSame(ErrorCategory::DEADLINE, $result->error->category);
    assertSame(0, $client->calls(), 'на оборот короче секунды не идём');
});

test('срок берётся из каталога, когда прогон его не задал', static function (): void {
    $client = new FakeChatClient([okBody('не успеет')]);

    $result = runWith($client, [], null, [], null, ['defaultDeadlineSeconds' => 0.3]);

    assertSame(Finish::DEADLINE, $result->finish);
    assertSame(0, $client->calls());
});

test('срок прогона сильнее каталожного', static function (): void {
    $config = new Config();
    $config->deadlineSeconds = 30.0;
    $client = new FakeChatClient([okBody('успел')]);

    $result = runWith($client, [], $config, [], null, ['defaultDeadlineSeconds' => 0.3]);

    assertTrue($result->success);
    assertSame('успел', $result->content);
});

suite('Цикл: события');

test('переключение модели приходит одним событием, возврат к запрошенной — не событие', static function (): void {
    // Оборот 1 — вызов инструмента; оборот 2 падает на m1, отвечает m2; лимит вызовов исчерпан,
    // добивка снова уходит на m1 (stickyFallback = false).
    $first = new FakeChatClient([
        toolBody('c1', 'echo_tool', ['text' => 'раз']),
        new RuntimeException('down', 500),
        okBody('итог'),
    ]);
    $second = new FakeChatClient([toolBody('c2', 'echo_tool', ['text' => 'два'])]);

    $config = new Config();
    $config->model = 'm1';
    $config->maxToolCalls = 1;
    $config->stickyFallback = false;

    $switches = [];
    $runner = new Runner(new Orchestra(catalogOfTwo($first, $second), null, new RecordingSleeper()));
    $result = $runner->run(
        [Message::user('вопрос')],
        new ToolBox([new EchoTool()]),
        systemPrompt(),
        $config,
        static function (string $event, string $content, array $meta) use (&$switches): void {
            if ($event === Event::MODEL_FALLBACK) {
                $switches[] = $meta['from'] . '→' . $meta['to'];
            }
        }
    );

    assertSame(['m1→m2'], $switches);
    assertSame('итог', $result->content);
});

test('неудачная попытка приходит с номером, пределом и признаком повтора', static function (): void {
    $client = new FakeChatClient([new RuntimeException('down', 500), okBody('со второй')]);

    $events = [];
    $runner = new Runner(new Orchestra(
        catalogOf($client, ['defaultPolicy' => ['retries' => 2, 'delay' => 3, 'backoff' => 1]]),
        null,
        new RecordingSleeper()
    ));
    $runner->run(
        [Message::user('вопрос')],
        new ToolBox([]),
        systemPrompt(),
        new Config(),
        static function (string $event, string $content, array $meta) use (&$events): void {
            if ($event === Event::ATTEMPT_FAILED) {
                $events[] = $meta;
            }
        }
    );

    assertCount(1, $events);
    assertSame(1, $events[0]['attempt']);
    assertSame(3, $events[0]['max_attempts'], 'предел попыток нужен интерфейсу для «повтор 1 из 3»');
    assertTrue($events[0]['will_retry']);
    assertSame(3.0, $events[0]['delay']);
});

test('вызов инструмента и его результат приходят событиями', static function (): void {
    $events = [];
    runWith(
        new FakeChatClient([toolBody('c1', 'echo_tool', ['text' => 'привет']), okBody('ок')]),
        [new EchoTool()],
        null,
        [],
        static function (string $event, string $content, array $meta) use (&$events): void {
            $events[] = $event;
        }
    );

    assertTrue(in_array(Event::TOOL_CALL, $events, true));
    assertTrue(in_array(Event::TOOL_RESULT, $events, true));
    assertTrue(in_array(Event::ASSISTANT_MESSAGE, $events, true));
});

suite('Цикл: приостановка и возобновление');

test('приостановленный вызов возвращает свой id и историю без ответа', static function (): void {
    $tool = new AskUserTool();
    $result = runWith(new FakeChatClient([toolBody('c1', 'ask_user')]), [$tool]);

    assertTrue($result->suspended);
    assertSame(Finish::SUSPENDED, $result->finish);
    assertSame(['c1'], $result->pendingToolCallIds);
    assertSame(1, $tool->calls);
});

test('возобновление дорешивает неотвеченный вызов, не трогая закрытые', static function (): void {
    $tool = new EchoTool();
    $history = [
        Message::user('вопрос'),
        Message::assistant('', [[
            'id'       => 'c1',
            'type'     => 'function',
            'function' => ['name' => 'echo_tool', 'arguments' => '{"text":"первый"}'],
        ]]),
        Message::tool('c1', '{"echo":"первый"}'),
        Message::assistant('', [[
            'id'       => 'c2',
            'type'     => 'function',
            'function' => ['name' => 'echo_tool', 'arguments' => '{"text":"второй"}'],
        ]]),
    ];

    $result = runWith(new FakeChatClient([okBody('дорешал')]), [$tool], null, $history);

    assertTrue($result->success);
    assertSame(1, $tool->calls, 'исполнен только незакрытый вызов');
    assertSame('второй', $tool->seenArgs[0]['text']);
});

test('повторяющиеся id вызовов между ходами не путают добор', static function (): void {
    $tool = new EchoTool();
    $call = static fn(string $text): array => [[
        'id'       => 'call_1',
        'type'     => 'function',
        'function' => ['name' => 'echo_tool', 'arguments' => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE)],
    ]];

    $history = [
        Message::user('вопрос'),
        Message::assistant('', $call('первый')),
        Message::tool('call_1', '{"echo":"первый"}'),
        Message::assistant('', $call('второй')),
    ];

    $result = runWith(new FakeChatClient([okBody('дорешал')]), [$tool], null, $history);

    assertSame(1, $tool->calls, 'вызов из последнего хода не считается отвеченным');
    assertSame('второй', $tool->seenArgs[0]['text']);
    assertTrue($result->success);
});

suite('Цикл: проверка аргументов');

test('протёкшая разметка вызова отбивается до исполнения инструмента', static function (): void {
    $tool = new EchoTool();
    $client = new FakeChatClient([
        toolBody('c1', 'echo_tool', ['text' => '<parameter name="text">привет</parameter>']),
        okBody('ок'),
    ]);

    $result = runWith($client, [$tool]);

    assertSame(0, $tool->calls, 'инструмент на испорченных аргументах не исполняется');
    assertContains('error', toolMessages($result)[0]);
});

test('проверку можно отключить', static function (): void {
    $tool = new EchoTool();
    $config = new Config();
    $config->toolArgsGuard = null;

    $client = new FakeChatClient([
        toolBody('c1', 'echo_tool', ['text' => '<parameter name="text">привет</parameter>']),
        okBody('ок'),
    ]);

    runWith($client, [$tool], $config);

    assertSame(1, $tool->calls);
});
