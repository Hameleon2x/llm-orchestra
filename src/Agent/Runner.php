<?php

namespace Hameleon2x\Llm\Agent;

use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Dto\Result;
use Hameleon2x\Llm\Agent\Enum\Event;
use Hameleon2x\Llm\Agent\Enum\Finish;
use Hameleon2x\Llm\Dto\AttemptLog;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Dto\ToolCall;
use Hameleon2x\Llm\Dto\ToolDefinition;
use Hameleon2x\Llm\Dto\Usage;
use Hameleon2x\Llm\Enum\Role;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorInfo;
use Hameleon2x\Llm\Factory\ToolCallFactory;
use Hameleon2x\Llm\Orchestra;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Агентский цикл: запрос к модели → исполнение вызовов инструментов → повтор, пока модель не даст
 * финальный ответ или не упрётся в лимиты.
 *
 * Не привязан ни к базе, ни к интерфейсу: историю, реестр инструментов, системный промт и реакцию
 * на события передаёт вызывающий код. Повторы и переключение моделей делает Orchestra — цикл
 * узнаёт о них через события и продолжает работу на той модели, которая ответила.
 *
 * ```php
 * $config = new Config();
 * $config->model = 'glm-4.6';
 *
 * $result = (new Runner($orchestra))->run($messages, $toolbox, fn() => 'Системный промт', $config);
 * echo $result->success ? $result->content : $result->finish;   // completed | error | suspended …
 * ```
 */
class Runner
{
    private Orchestra $orchestra;

    private LoggerInterface $logger;

    public function __construct(Orchestra $orchestra, ?LoggerInterface $logger = null)
    {
        $this->orchestra = $orchestra;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Прогнать агентский цикл.
     *
     * @param Message[]     $messages       история диалога без системного сообщения
     * @param callable      $systemPromptFn function(Message[] $history): string
     * @param callable|null $emit           function(string $event, string $content, array $meta): void
     */
    public function run(
        array            $messages,
        ToolboxInterface $toolbox,
        callable         $systemPromptFn,
        Config           $config,
        ?callable        $emit = null
    ): Result {
        // Приёмник событий — вспомогательный канал (прогресс в интерфейсе, запись в базу). Его сбой
        // не должен обрывать прогон: инструменты уже отработали с побочными эффектами, а история
        // живёт внутри run() и была бы потеряна. Так же изолирован наблюдатель попыток в Orchestra.
        $emit = $this->safeEmit($emit);

        $startedAt = microtime(true);
        $usage = new Usage();

        try {
            $tools = $toolbox->definitions();
        } catch (Throwable $e) {
            return $this->failedOnAppCode('Реестр инструментов не собрался', $e, $messages, $usage);
        }

        $paramNames = $this->collectParamNames($tools);
        $toolCallsLeft = $config->maxToolCalls;
        $attempts = [];
        $currentModel = $config->model;
        $lastResponse = null;

        $orchestra = $this->prepareOrchestra($config, $emit);

        // Возобновление прерванного хода: в истории мог остаться ассистентский ход с вызовами без
        // ответов — инструмент ждал внешнего ввода либо прогон оборвался посреди исполнения.
        // Дорешиваем их тем же путём, что и обычный ход.
        $pending = $this->findUnansweredToolCalls($messages);
        if ($pending !== []) {
            $outcome = $this->executeToolCalls($pending, $toolbox, $config, $paramNames, $messages, $toolCallsLeft, $emit);
            if ($outcome['suspendedIds'] !== []) {
                return $this->finalize(
                    Result::suspended(
                        $outcome['suspendedIds'],
                        $messages,
                        0,
                        $config->maxToolCalls - $toolCallsLeft,
                        $usage
                    ),
                    $currentModel,
                    $attempts,
                    $lastResponse
                );
            }

            // Бюджет вызовов мог кончиться уже на доборе: тогда идём сразу к добивке, а не тратим
            // ещё один запрос к модели на ход, все вызовы которого всё равно будут отклонены.
            if ($outcome['limitExhausted']) {
                return $this->finishOnToolLimit(
                    $this->withinDeadline($orchestra, $config, $startedAt),
                    $messages,
                    $systemPromptFn,
                    $config,
                    $currentModel,
                    0,
                    $usage,
                    $attempts,
                    $lastResponse
                );
            }
        }

        for ($turn = 0; $turn < $config->maxTurns; $turn++) {
            $turnsUsed = $turn + 1;
            $toolCallsUsed = $config->maxToolCalls - $toolCallsLeft;

            if ($this->deadlineExceeded($config, $startedAt)) {
                return $this->finalize(
                    Result::error(
                        $this->deadlineError($config),
                        $messages,
                        $turn,
                        $toolCallsUsed,
                        $usage,
                        Finish::DEADLINE
                    ),
                    $currentModel,
                    $attempts,
                    $lastResponse
                );
            }

            // Системный промт между оборотами неизменен: стабильный префикс запроса позволяет
            // провайдеру переиспользовать кеш промпта. Пояснения по инструментам подмешиваются
            // в результат инструмента при первом вызове (см. executeToolCalls).
            try {
                $systemPrompt = (string)$systemPromptFn($messages);
            } catch (Throwable $e) {
                return $this->failedOnAppCode(
                    'Не удалось собрать системный промт',
                    $e,
                    $messages,
                    $usage,
                    $turnsUsed,
                    $toolCallsUsed,
                    $currentModel,
                    $attempts,
                    $lastResponse
                );
            }

            $request = $this->buildRequest($systemPrompt, $messages, $tools, $config);
            $response = $this->withinDeadline($orchestra, $config, $startedAt)->execute($request, $currentModel);

            $attempts = array_merge($attempts, $response->attempts);
            // Потребление считаем только по состоявшимся вызовам: у неудачной попытки блока usage
            // нет, а число обращений к модели видно по журналу попыток.
            if ($response->isSuccess()) {
                $usage->add($response->usage, $response->modelKey);
            }

            if (!$response->isSuccess()) {
                return $this->finalize(
                    Result::error($response->error, $messages, $turnsUsed, $toolCallsUsed, $usage),
                    $currentModel,
                    $attempts,
                    $lastResponse
                );
            }

            $lastResponse = $response;
            if ($config->stickyFallback && $response->modelKey !== '') {
                $currentModel = $response->modelKey;
            }

            if (!$response->hasToolCalls()) {
                $content = trim((string)$response->content);
                $messages[] = Message::assistant($content);

                return $this->finalize(
                    Result::success($content, $messages, $turnsUsed, $toolCallsUsed, $usage),
                    $currentModel,
                    $attempts,
                    $response
                );
            }

            $assistantToolCalls = array_map(
                static fn(ToolCall $toolCall) => ToolCallFactory::toArray($toolCall),
                $response->toolCalls
            );

            $emit(Event::ASSISTANT_MESSAGE, (string)$response->content, [
                'tool_calls' => $assistantToolCalls,
                'extra'      => $response->extra,
                'usage'      => $response->usage->toArray(),
                'model'      => $response->modelKey,
            ]);

            // Порядок для API: ассистент с tool_calls — непосредственно перед сообщениями tool.
            $messages[] = Message::assistant((string)$response->content, $assistantToolCalls);

            // TOOL_CALL шлём здесь, на получении хода, один раз по всем вызовам: тогда добор
            // неотвеченных вызовов при возобновлении не порождает повторных событий.
            foreach ($response->toolCalls as $toolCall) {
                $emit(Event::TOOL_CALL, $toolCall->getFunctionName(), [
                    'tool_call_id' => $toolCall->id,
                    'tool'         => $toolCall->getFunctionName(),
                    'args'         => $toolCall->getArguments(),
                ]);
            }

            $outcome = $this->executeToolCalls(
                $response->toolCalls,
                $toolbox,
                $config,
                $paramNames,
                $messages,
                $toolCallsLeft,
                $emit
            );

            if ($outcome['suspendedIds'] !== []) {
                // В ходе есть приостановленные вызовы: внешний код предоставит их результаты и
                // возобновит прогон, когда закрыты все вызовы хода.
                return $this->finalize(
                    Result::suspended(
                        $outcome['suspendedIds'],
                        $messages,
                        $turnsUsed,
                        $config->maxToolCalls - $toolCallsLeft,
                        $usage
                    ),
                    $currentModel,
                    $attempts,
                    $lastResponse
                );
            }

            if ($outcome['limitExhausted']) {
                return $this->finishOnToolLimit(
                    $this->withinDeadline($orchestra, $config, $startedAt),
                    $messages,
                    $systemPromptFn,
                    $config,
                    $currentModel,
                    $turnsUsed,
                    $usage,
                    $attempts,
                    $lastResponse
                );
            }
        }

        $messages[] = Message::assistant($config->turnsExhaustedText);

        return $this->finalize(
            Result::success(
                $config->turnsExhaustedText,
                $messages,
                $config->maxTurns,
                $config->maxToolCalls - $toolCallsLeft,
                $usage,
                Finish::TURNS_EXHAUSTED
            ),
            $currentModel,
            $attempts,
            $lastResponse
        );
    }

    /**
     * Прогон, оборванный сбоем прикладного кода: системного промта или реестра инструментов.
     *
     * Пробрасывать такое исключение нельзя — вместе с ним потерялась бы вся история, которая живёт
     * внутри run(), а инструменты этого прогона уже отработали с побочными эффектами. Категория —
     * `config`: чинится не повтором, а исправлением кода приложения.
     *
     * @param Message[]    $messages
     * @param AttemptLog[] $attempts
     */
    private function failedOnAppCode(
        string    $what,
        Throwable $e,
        array     $messages,
        Usage     $usage,
        int       $turnsUsed = 0,
        int       $toolCallsUsed = 0,
        ?string   $modelKey = null,
        array     $attempts = [],
        ?Response $lastResponse = null
    ): Result {
        $this->logger->error('LLM run aborted by application code', [
            'stage'     => $what,
            'message'   => $e->getMessage(),
            'exception' => get_class($e),
        ]);

        $error = new ErrorInfo(ErrorCategory::CONFIG, $what . ': ' . $e->getMessage(), false);

        return $this->finalize(
            Result::error($error, $messages, $turnsUsed, $toolCallsUsed, $usage),
            $modelKey,
            $attempts,
            $lastResponse
        );
    }

    /**
     * Исполнитель, которому осталось не больше времени, чем осталось у прогона.
     *
     * Без этого срок прогона проверялся бы только на границе оборота, и один оборот с повторами и
     * переключениями законно уезжал бы далеко за дедлайн. Потолок каталога при этом не повышается —
     * берётся меньшее из двух.
     */
    private function withinDeadline(Orchestra $orchestra, Config $config, float $startedAt): Orchestra
    {
        if ($config->deadlineSeconds === null) {
            return $orchestra;
        }

        $left = $config->deadlineSeconds - (microtime(true) - $startedAt);
        $left = max(0.0, $left);

        $catalog = $orchestra->registry()->maxTotalWaitSeconds();

        return $orchestra->withTotalWaitSeconds($catalog === null ? $left : min($left, $catalog));
    }

    /**
     * Обёртка приёмника событий, которая не пробрасывает исключения наружу.
     */
    private function safeEmit(?callable $emit): callable
    {
        if ($emit === null) {
            return static function (): void {
            };
        }

        return function (string $event, string $content, array $meta = []) use ($emit): void {
            try {
                $emit($event, $content, $meta);
            } catch (Throwable $e) {
                $this->logger->warning('LLM event sink failed', [
                    'event'   => $event,
                    'message' => $e->getMessage(),
                ]);
            }
        };
    }

    /**
     * Копия исполнителя на этот прогон: переопределения из конфига плюс трансляция попыток
     * и переключений моделей в события цикла.
     */
    private function prepareOrchestra(Config $config, callable $emit): Orchestra
    {
        $orchestra = $this->orchestra;

        if ($config->policy !== null) {
            $orchestra = $orchestra->withPolicy($config->policy);
        }
        if ($config->fallback !== null || $config->maxSwitches !== null) {
            $orchestra = $orchestra->withFallback(
                $config->fallback ?? $orchestra->registry()->fallbackChain(),
                $config->maxSwitches
            );
        }

        $seenModel = null;

        return $orchestra->withObserver(static function (AttemptLog $attempt) use ($emit, &$seenModel): void {
            // Модель запоминаем до отправки события: иначе сбой приёмника заставил бы прислать
            // одно и то же переключение ещё раз на следующей попытке.
            $previousModel = $seenModel;
            $seenModel = $attempt->modelKey;

            if ($previousModel !== null && $attempt->modelKey !== $previousModel) {
                $emit(Event::MODEL_FALLBACK, $attempt->modelKey, [
                    'from' => $previousModel,
                    'to'   => $attempt->modelKey,
                ]);
            }

            if ($attempt->success || $attempt->error === null) {
                return;
            }

            $emit(Event::ATTEMPT_FAILED, $attempt->error->category, [
                'model'      => $attempt->modelKey,
                'provider'   => $attempt->providerKey,
                'attempt'    => $attempt->attempt,
                'category'   => $attempt->error->category,
                'message'    => $attempt->error->message,
                'will_retry' => $attempt->willRetry,
                'delay'      => $attempt->nextDelay,
            ]);
        });
    }

    /**
     * @param Message[]        $messages
     * @param ToolDefinition[] $tools
     */
    private function buildRequest(string $systemPrompt, array $messages, array $tools, Config $config): Request
    {
        $withSystem = array_merge([Message::system($systemPrompt)], $messages);

        $request = Request::withTools($withSystem, $tools, $config->toolChoice);
        $request->setParams($config->params);
        if ($config->extraParams !== []) {
            $request->setExtraParams($config->extraParams);
        }

        return $request;
    }

    /**
     * Лимит вызовов инструментов исчерпан: добавляем сообщение-добивку и просим итоговый ответ
     * без инструментов.
     *
     * @param Message[]    $messages
     * @param AttemptLog[] $attempts
     */
    private function finishOnToolLimit(
        Orchestra $orchestra,
        array     $messages,
        callable  $systemPromptFn,
        Config    $config,
        ?string   $modelKey,
        int       $turnsUsed,
        Usage     $usage,
        array     $attempts,
        ?Response $lastResponse = null
    ): Result {
        $toolCallsUsed = $config->maxToolCalls;

        // История без подталкивающего сообщения: если добивка не удастся, отдать её наружу нельзя —
        // приложение сохранит историю, и в диалоге появится реплика пользователя, которой не было.
        $messagesBeforeNudge = $messages;
        $messages[] = Message::user($config->limitNudgeMessage);

        try {
            $systemPrompt = (string)$systemPromptFn($messages);
        } catch (Throwable $e) {
            return $this->failedOnAppCode(
                'Не удалось собрать системный промт',
                $e,
                $messagesBeforeNudge,
                $usage,
                $turnsUsed,
                $toolCallsUsed,
                $modelKey,
                $attempts,
                $lastResponse
            );
        }

        $request = Request::messages(array_merge([Message::system($systemPrompt)], $messages));
        $request->setParams($config->params);
        if ($config->extraParams !== []) {
            $request->setExtraParams($config->extraParams);
        }

        $response = $orchestra->execute($request, $modelKey);
        $attempts = array_merge($attempts, $response->attempts);

        if ($response->isSuccess()) {
            $usage->add($response->usage, $response->modelKey);
        }

        // Сбой добивки — такой же сбой вызова модели, как и на любом обороте: отдаём ошибку с
        // категорией, а не заглушку об исчерпанном лимите. История и результаты инструментов
        // остаются в Result.
        if (!$response->isSuccess()) {
            return $this->finalize(
                Result::error($response->error, $messagesBeforeNudge, $turnsUsed, $toolCallsUsed, $usage),
                $modelKey,
                $attempts,
                $lastResponse
            );
        }

        if (trim((string)$response->content) !== '') {
            $messages[] = Message::assistant((string)$response->content);

            return $this->finalize(
                Result::success(
                    (string)$response->content,
                    $messages,
                    $turnsUsed,
                    $toolCallsUsed,
                    $usage,
                    Finish::TOOL_LIMIT
                ),
                $response->modelKey !== '' ? $response->modelKey : $modelKey,
                $attempts,
                $response
            );
        }

        $messages[] = Message::assistant($config->limitFallbackText);

        return $this->finalize(
            Result::success(
                $config->limitFallbackText,
                $messages,
                $turnsUsed,
                $toolCallsUsed,
                $usage,
                Finish::TOOL_LIMIT
            ),
            $modelKey,
            $attempts,
            $response
        );
    }

    /**
     * Исполнить набор вызовов: обычный инструмент — выполнить и дописать tool-сообщение;
     * приостановленный — собрать его id (результат придёт извне). Расходует бюджет $toolCallsLeft;
     * при его исчерпании оставшиеся вызовы закрываются ошибкой, чтобы ход остался полностью
     * отвечён, и возвращается limitExhausted = true.
     *
     * Единый путь и для обычного хода, и для добора неотвеченных вызовов при возобновлении.
     *
     * @param ToolCall[]              $toolCalls
     * @param array<string, string[]> $paramNames    имена параметров по имени инструмента
     * @param Message[]               $messages      дополняется tool-сообщениями (по ссылке)
     * @param int                     $toolCallsLeft остаток бюджета вызовов (по ссылке)
     * @return array{suspendedIds: string[], limitExhausted: bool}
     */
    private function executeToolCalls(
        array            $toolCalls,
        ToolboxInterface $toolbox,
        Config           $config,
        array            $paramNames,
        array            &$messages,
        int              &$toolCallsLeft,
        callable         $emit
    ): array {
        $suspendedIds = [];
        $limitExhausted = false;

        foreach ($toolCalls as $toolCall) {
            if ($toolCallsLeft <= 0) {
                $limitExhausted = true;
            }

            if ($limitExhausted) {
                // Бюджет исчерпан: оставшиеся вызовы закрываем ошибкой, иначе завершённый ход
                // повис бы без ответов и сломал следующий запрос и логику возобновления.
                $this->answerWithError(
                    $toolCall,
                    'Достигнут лимит вызовов инструментов за прогон.',
                    $messages,
                    $emit
                );
                continue;
            }

            $toolCallsLeft--;

            $toolName = $toolCall->getFunctionName();
            $args = $toolCall->getArguments();

            if ($config->toolArgsGuard !== null) {
                $leak = $config->toolArgsGuard->findLeak($args, $paramNames[$toolName] ?? []);
                if ($leak !== null) {
                    $this->answerWithError($toolCall, $leak, $messages, $emit, true);
                    continue;
                }
            }

            try {
                $result = $toolbox->execute($toolName, $args);
            } catch (Throwable $e) {
                // Сбой инструмента — не сбой прогона: закрываем вызов ошибкой, модель увидит её на
                // следующем ходу. Иначе исключение прикладного кода оборвало бы весь цикл и ход
                // остался бы без ответов на уже сделанные вызовы.
                //
                // Модели уходит нейтральный текст: сообщение исключения пишут для разработчика, оно
                // бывает огромным и содержит внутренности (SQL с параметрами, пути, персональные
                // данные), а история отправляется провайдеру и повторяется на каждом обороте.
                // Подробности — в лог.
                $this->logger->error('LLM tool threw an exception', [
                    'tool'      => $toolName,
                    'message'   => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                $this->answerWithError(
                    $toolCall,
                    $this->toolExceptionText($e, $config),
                    $messages,
                    $emit,
                    false,
                    true
                );
                continue;
            }

            if ($result->isSuspended()) {
                // Инструмент ждёт внешнего ввода: tool-сообщение сейчас не пишем, только копим id.
                $suspendedIds[] = $toolCall->id;
                continue;
            }

            $resultArray = $result->toJsonArray();

            // Первый вызов инструмента в истории — кладём в его результат пояснение о том, как
            // читать поля ответа. Дописывается в хвост истории один раз за диалог, поэтому
            // системный префикс запроса остаётся стабильным.
            if ($this->isFirstUse($toolName, $toolCall->id, $messages)) {
                try {
                    $hint = trim($toolbox->firstUseHint($toolName));
                    if ($hint !== '') {
                        $resultArray[$toolbox->firstUseHintKey($toolName)] = $hint;
                    }
                } catch (Throwable $e) {
                    // Пояснение необязательно, а инструмент уже отработал: уронить прогон здесь
                    // значит потерять его результат и получить повторное исполнение при следующем
                    // запуске.
                    $this->logger->warning('LLM first-use hint failed', [
                        'tool'    => $toolName,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $content = self::encodeForModel($resultArray);

            $emit(Event::TOOL_RESULT, $content, [
                'tool_call_id' => $toolCall->id,
                'tool'         => $toolName,
                'ok'           => $result->ok,
            ]);

            $messages[] = Message::tool($toolCall->id, $content);
        }

        return ['suspendedIds' => $suspendedIds, 'limitExhausted' => $limitExhausted];
    }

    /**
     * Закрыть вызов ошибкой: модель увидит её на следующем ходу и сможет отреагировать.
     *
     * @param Message[] $messages дополняется по ссылке
     */
    private function answerWithError(
        ToolCall $toolCall,
        string   $message,
        array    &$messages,
        callable $emit,
        bool     $guard = false,
        bool     $exception = false
    ): void {
        $content = self::encodeForModel(['error' => $message]);

        $meta = [
            'tool_call_id' => $toolCall->id,
            'tool'         => $toolCall->getFunctionName(),
            'ok'           => false,
        ];
        if ($guard) {
            $meta['guard'] = true;
        }
        if ($exception) {
            // Интерфейсу и аудиту нужно отличать «инструмент сообщил о неудаче» от «инструмент упал»:
            // в первом случае текст писал автор инструмента, во втором — это внутренний сбой.
            $meta['exception'] = true;
        }

        $emit(Event::TOOL_RESULT, $content, $meta);
        $messages[] = Message::tool($toolCall->id, $content);
    }

    /**
     * Что увидит модель вместо результата упавшего инструмента.
     *
     * Сообщение исключения показывается, только если приложение это разрешило: обычно оно написано
     * для разработчика и содержит внутренности, а история уходит провайдеру и повторяется на каждом
     * обороте. Разрешённое сообщение приводится к одной строке и обрезается.
     */
    private function toolExceptionText(Throwable $e, Config $config): string
    {
        if (!$config->exposeToolExceptions) {
            return 'Инструмент завершился внутренней ошибкой. Повторять вызов с теми же аргументами бессмысленно.';
        }

        $message = trim(preg_replace('/\s+/u', ' ', $e->getMessage()) ?? '');
        if ($message === '') {
            return 'Инструмент завершился внутренней ошибкой.';
        }

        if (mb_strlen($message) > Config::TOOL_EXCEPTION_MAX_LENGTH) {
            $message = mb_substr($message, 0, Config::TOOL_EXCEPTION_MAX_LENGTH) . '…';
        }

        return 'Инструмент завершился ошибкой: ' . $message;
    }

    /**
     * JSON для tool-сообщения. Битые последовательности заменяются, а не роняют кодирование:
     * пустое tool-сообщение выглядело бы для модели как «инструмент ничего не ответил».
     */
    private static function encodeForModel(array $payload): string
    {
        $content = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($content === false) {
            $content = json_encode(
                ['error' => 'Результат инструмента не удалось сериализовать в JSON.'],
                JSON_UNESCAPED_UNICODE
            );
        }

        return (string)$content;
    }

    /**
     * Имена параметров каждого инструмента — нужны проверке аргументов: тег, названный по имени
     * параметра, в значении другого параметра означает протёкшую разметку вызова.
     *
     * @param ToolDefinition[] $tools
     * @return array<string, string[]>
     */
    private function collectParamNames(array $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            $name = (string)($tool->function['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $properties = $tool->function['parameters']['properties'] ?? [];
            $names[$name] = is_array($properties) ? array_map('strval', array_keys($properties)) : [];
        }

        return $names;
    }

    /**
     * Первый ли это вызов инструмента в истории. Первым считается самое раннее вхождение имени в
     * ассистентских ходах: если его id совпадает с текущим вызовом — это первый вызов. Так
     * пояснение подмешивается ровно один раз за диалог, включая случай нескольких одноимённых
     * вызовов в одном ходе.
     *
     * @param Message[] $messages история с уже дописанным ходом текущего вызова
     */
    private function isFirstUse(string $toolName, string $currentCallId, array $messages): bool
    {
        foreach ($messages as $message) {
            if ($message->role !== Role::ASSISTANT || empty($message->toolCalls)) {
                continue;
            }
            foreach ($message->toolCalls as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                if (($raw['function']['name'] ?? null) === $toolName) {
                    return ($raw['id'] ?? null) === $currentCallId;
                }
            }
        }

        return true;
    }

    /**
     * Вызовы инструментов, оставшиеся без ответного tool-сообщения. Возникают при возобновлении:
     * инструмент ждёт внешнего ввода либо прогон оборвался посреди исполнения.
     *
     * Разница множеств безопасна, потому что держится инвариант «завершённый ход всегда полностью
     * отвечён»: обычные ходы закрываются перед следующим ассистентом, а ход, обрезанный лимитом
     * вызовов, закрывается ошибками в executeToolCalls. Значит без ответа может остаться только
     * текущий незавершённый ход.
     *
     * @param Message[] $messages
     * @return ToolCall[]
     */
    private function findUnansweredToolCalls(array $messages): array
    {
        $answeredIds = [];
        foreach ($messages as $message) {
            if ($message->role === Role::TOOL && $message->toolCallId !== null) {
                $answeredIds[$message->toolCallId] = true;
            }
        }

        $unanswered = [];
        foreach ($messages as $message) {
            if ($message->role !== Role::ASSISTANT || empty($message->toolCalls)) {
                continue;
            }
            foreach ($message->toolCalls as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $id = $raw['id'] ?? null;
                if ($id === null || isset($answeredIds[$id])) {
                    continue;
                }
                $unanswered[] = ToolCallFactory::fromArray($raw);
            }
        }

        return $unanswered;
    }

    private function deadlineExceeded(Config $config, float $startedAt): bool
    {
        if ($config->deadlineSeconds === null) {
            return false;
        }

        return (microtime(true) - $startedAt) >= $config->deadlineSeconds;
    }

    private function deadlineError(Config $config): ErrorInfo
    {
        return new ErrorInfo(
            ErrorCategory::DEADLINE,
            'Истёк отведённый на прогон срок (' . $config->deadlineSeconds . ' с).',
            false
        );
    }

    /**
     * Дописать в результат сведения о прогоне: модель, журнал попыток, последний ответ.
     *
     * @param AttemptLog[] $attempts
     */
    private function finalize(Result $result, ?string $modelKey, array $attempts, ?Response $lastResponse): Result
    {
        $result->modelKey = (string)$modelKey;
        $result->attempts = $attempts;
        $result->lastResponse = $lastResponse;

        return $result;
    }
}
