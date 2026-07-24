<?php

/**
 * Общая обвязка тестов: автозагрузка, проверки, поддельный транспорт и типовые инструменты.
 *
 * Внешних зависимостей нет намеренно — библиотека их тоже не имеет, а тесты должны запускаться
 * сразу после клонирования: `php tests/run.php`.
 */

use Hameleon2x\Llm\Agent\AbstractToolbox;
use Hameleon2x\Llm\Http\ChatClientInterface;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;
use Hameleon2x\Llm\Support\SleeperInterface;
use Hameleon2x\Llm\Tool\AbstractTool;
use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result as ToolResult;

// psr/log — единственная зависимость библиотеки, она нужна и тестам.
if (!is_file(__DIR__ . '/../vendor/autoload.php')) {
    fwrite(STDERR, "Нет vendor/autoload.php — выполните composer install.\n");
    exit(2);
}
require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Hameleon2x\\Llm\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// --- регистрация и запуск проверок -------------------------------------------------------------

final class TestRegistry
{
    /** @var array<string, array<string, callable>> набор → название → проверка */
    public static array $cases = [];

    public static string $currentSuite = 'Разное';
}

/** Начать набор проверок: все последующие test() попадут в него. */
function suite(string $title): void
{
    TestRegistry::$currentSuite = $title;
}

/** Зарегистрировать проверку. Падение — исключение из assert-функции. */
function test(string $title, callable $case): void
{
    TestRegistry::$cases[TestRegistry::$currentSuite][$title] = $case;
}

final class AssertionFailed extends RuntimeException
{
}

function fail(string $message): void
{
    throw new AssertionFailed($message);
}

function assertTrue($value, string $message = 'ожидалось true'): void
{
    if ($value !== true) {
        fail($message . ' (получено ' . export($value) . ')');
    }
}

function assertFalse($value, string $message = 'ожидалось false'): void
{
    if ($value !== false) {
        fail($message . ' (получено ' . export($value) . ')');
    }
}

function assertSame($expected, $actual, string $message = 'значения различаются'): void
{
    if ($expected !== $actual) {
        fail($message . ': ожидалось ' . export($expected) . ', получено ' . export($actual));
    }
}

function assertNull($value, string $message = 'ожидался null'): void
{
    if ($value !== null) {
        fail($message . ' (получено ' . export($value) . ')');
    }
}

function assertCount(int $expected, array $actual, string $message = 'не совпало количество'): void
{
    if (count($actual) !== $expected) {
        fail($message . ': ожидалось ' . $expected . ', получено ' . count($actual));
    }
}

function assertContains(string $needle, string $haystack, string $message = 'подстрока не найдена'): void
{
    if (strpos($haystack, $needle) === false) {
        fail($message . ': «' . $needle . '» нет в «' . mb_substr($haystack, 0, 200) . '»');
    }
}

/** Проверка, что вызов бросает исключение указанного класса; возвращает его сообщение. */
function assertThrows(string $class, callable $fn, string $message = 'исключения не было'): string
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            fail($message . ': ожидался ' . $class . ', получен ' . get_class($e) . ' (' . $e->getMessage() . ')');
        }

        return $e->getMessage();
    }

    fail($message . ': ожидался ' . $class);

    return '';
}

function export($value): string
{
    if (is_array($value)) {
        return 'array(' . count($value) . ')' . json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    if (is_object($value)) {
        return get_class($value);
    }

    return var_export($value, true);
}

// --- поддельное окружение ----------------------------------------------------------------------

/**
 * Транспорт с очередью заготовленных ответов. Элемент-исключение бросается вместо ответа —
 * так проверяются сбои провайдера.
 */
final class FakeChatClient implements ChatClientInterface
{
    /** @var array<int, string|Throwable> */
    private array $queue;

    /** @var array<int, array> отправленные payload'ы */
    public array $sent = [];

    /** @var array<int, ?int> таймауты, с которыми звали транспорт */
    public array $timeouts = [];

    public function __construct(array $queue = [])
    {
        $this->queue = $queue;
    }

    public function chat(array $payload, array $headers = [], ?int $timeout = null): string
    {
        $this->sent[] = $payload;
        $this->timeouts[] = $timeout;

        $next = array_shift($this->queue);
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next ?? okBody('очередь пуста');
    }

    public function calls(): int
    {
        return count($this->sent);
    }

    public function lastPayload(): array
    {
        return $this->sent[count($this->sent) - 1] ?? [];
    }
}

/** Пауз в тестах не ждём, но записываем — по ним видно расчёт задержек. */
final class RecordingSleeper implements SleeperInterface
{
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
    }
}

/** Тело успешного ответа модели. */
function okBody(string $text, array $extra = []): string
{
    return json_encode(array_merge([
        'model'   => 'model-slug',
        'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
        'usage'   => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], $extra), JSON_UNESCAPED_UNICODE);
}

/** Тело ответа с одним вызовом инструмента. */
function toolBody(string $id, string $name, array $args = []): string
{
    return json_encode([
        'choices' => [[
            'message' => [
                'content'    => '',
                'tool_calls' => [[
                    'id'       => $id,
                    'type'     => 'function',
                    'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]],
        'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
    ], JSON_UNESCAPED_UNICODE);
}

/** Ход модели без текста и без вызовов — «отвечать нечем». */
function emptyBody(): string
{
    return json_encode([
        'choices' => [['message' => ['content' => ''], 'finish_reason' => 'stop']],
    ]);
}

/** Каталог из одной модели на поддельном транспорте. */
function catalogOf(FakeChatClient $client, array $extra = []): Registry
{
    return Registry::fromArray(array_merge([
        'providers'     => ['p' => ['class' => OpenAiProvider::class, 'httpClient' => $client]],
        'models'        => ['m' => ['provider' => 'p', 'name' => 'model-slug']],
        'defaultModel'  => 'm',
        'defaultPolicy' => ['retries' => 0],
    ], $extra));
}

/** Каталог из двух моделей с цепочкой m1 → m2, каждая на своём транспорте. */
function catalogOfTwo(FakeChatClient $first, FakeChatClient $second, array $extra = []): Registry
{
    return Registry::fromArray(array_merge([
        'providers'     => [
            'p1' => ['class' => OpenAiProvider::class, 'httpClient' => $first],
            'p2' => ['class' => OpenAiProvider::class, 'httpClient' => $second],
        ],
        'models'        => [
            'm1' => ['provider' => 'p1', 'name' => 'model-1'],
            'm2' => ['provider' => 'p2', 'name' => 'model-2'],
        ],
        'defaultModel'  => 'm1',
        'fallback'      => ['m1', 'm2'],
        'defaultPolicy' => ['retries' => 0],
    ], $extra));
}

/** Системный промт прогона — в тестах он неизменен. */
function systemPrompt(): callable
{
    return static fn(array $history): string => 'system prompt';
}

// --- инструменты для агентского цикла ----------------------------------------------------------

/** Обычный инструмент: возвращает объект. */
final class EchoTool extends AbstractTool
{
    public int $calls = 0;

    /** @var array<int, array> аргументы каждого вызова */
    public array $seenArgs = [];

    public function getName(): string
    {
        return 'echo_tool';
    }

    public function getDescription(): string
    {
        return 'Возвращает переданный текст.';
    }

    public function getParameters(): array
    {
        return [new Property('text', 'string', 'Текст', false)];
    }

    public function execute(array $args): ToolResult
    {
        $this->calls++;
        $this->seenArgs[] = $args;

        return ToolResult::ok(['echo' => (string)($args['text'] ?? '')]);
    }

    public function firstUseHint(): string
    {
        return 'Поле echo повторяет вход.';
    }
}

/** Инструмент, отвечающий списком: такому результату ключ пояснения добавить нельзя. */
final class ListTool extends AbstractTool
{
    public function getName(): string
    {
        return 'list_tool';
    }

    public function getDescription(): string
    {
        return 'Возвращает список.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        return ToolResult::ok(['первый', 'второй']);
    }

    public function firstUseHint(): string
    {
        return 'Список отсортирован по релевантности.';
    }
}

/** Инструмент, падающий с исключением: его текст не должен уходить модели. */
final class BoomTool extends AbstractTool
{
    public function getName(): string
    {
        return 'boom';
    }

    public function getDescription(): string
    {
        return 'Всегда падает.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        throw new RuntimeException('SQLSTATE[23000] секрет в сообщении');
    }
}

/** Инструмент, приостанавливающий прогон ради ответа пользователя. */
final class AskUserTool extends AbstractTool
{
    public int $calls = 0;

    public function getName(): string
    {
        return 'ask_user';
    }

    public function getDescription(): string
    {
        return 'Спрашивает пользователя.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $args): ToolResult
    {
        $this->calls++;

        return ToolResult::suspend();
    }
}

/** Реестр инструментов из готового списка. */
final class ToolBox extends AbstractToolbox
{
    private array $tools;

    public function __construct(array $tools = [])
    {
        $this->tools = $tools;
    }

    protected function buildTools(): array
    {
        return $this->tools;
    }
}
