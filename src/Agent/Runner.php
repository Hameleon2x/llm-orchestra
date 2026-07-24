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
 * echo $result->success ? $result->content : $result->error->category;
 * ```
 */
class Runner
{
    private Orchestra $orchestra;

    public function __construct(Orchestra $orchestra)
    {
        $this->orchestra = $orchestra;
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
        $emit = $emit ?? static function (): void {
        };

        $startedAt = microtime(true);
        $tools = $toolbox->definitions();
        $paramNames = $this->collectParamNames($tools);
        $toolCallsLeft = $config->maxToolCalls;
        $usage = new Usage();
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
            $systemPrompt = (string)$systemPromptFn($messages);

            $request = $this->buildRequest($systemPrompt, $messages, $tools, $config);
            $response = $orchestra->execute($request, $currentModel);

            $usage->add($response->usage, $response->modelKey);
            $attempts = array_merge($attempts, $response->attempts);

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
                    $orchestra,
                    $messages,
                    $systemPromptFn,
                    $config,
                    $currentModel,
                    $turnsUsed,
                    $usage,
                    $attempts
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
     * Копия исполнителя на этот прогон: переопределения из конфига плюс трансляция попыток
     * и переключений моделей в события цикла.
     */
    private function prepareOrchestra(Config $config, callable $emit): Orchestra
    {
        $orchestra = $this->orchestra;

        if ($config->policy !== null) {
            $orchestra = $orchestra->withPolicy($config->policy);
        }
        if ($config->fallback !== null) {
            $orchestra = $orchestra->withFallback($config->fallback);
        }

        $seenModel = null;

        return $orchestra->withObserver(static function (AttemptLog $attempt) use ($emit, &$seenModel): void {
            if ($seenModel !== null && $attempt->modelKey !== $seenModel) {
                $emit(Event::MODEL_FALLBACK, $attempt->modelKey, [
                    'from' => $seenModel,
                    'to'   => $attempt->modelKey,
                ]);
            }
            $seenModel = $attempt->modelKey;

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
        array     $attempts
    ): Result {
        $toolCallsUsed = $config->maxToolCalls;

        $messages[] = Message::user($config->limitNudgeMessage);

        $systemPrompt = (string)$systemPromptFn($messages);
        $request = Request::messages(array_merge([Message::system($systemPrompt)], $messages));
        $request->setParams($config->params);
        if ($config->extraParams !== []) {
            $request->setExtraParams($config->extraParams);
        }

        $response = $orchestra->execute($request, $modelKey);
        $usage->add($response->usage, $response->modelKey);
        $attempts = array_merge($attempts, $response->attempts);

        if ($response->isSuccess() && trim((string)$response->content) !== '') {
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
            $response->isSuccess() ? $response : null
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

            $result = $toolbox->execute($toolName, $args);

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
                $hint = trim($toolbox->firstUseHint($toolName));
                if ($hint !== '') {
                    $resultArray[$toolbox->firstUseHintKey($toolName)] = $hint;
                }
            }

            $content = json_encode($resultArray, JSON_UNESCAPED_UNICODE);

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
        bool     $guard = false
    ): void {
        $content = json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);

        $meta = [
            'tool_call_id' => $toolCall->id,
            'tool'         => $toolCall->getFunctionName(),
            'ok'           => false,
        ];
        if ($guard) {
            $meta['guard'] = true;
        }

        $emit(Event::TOOL_RESULT, $content, $meta);
        $messages[] = Message::tool($toolCall->id, $content);
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
