<?php

namespace Hameleon2x\Llm\Agent;

use Hameleon2x\Llm\Agent\Dto\Config;
use Hameleon2x\Llm\Agent\Dto\Result;
use Hameleon2x\Llm\Agent\Dto\Usage;
use Hameleon2x\Llm\Agent\Enum\Event;
use Hameleon2x\Llm\Client;
use Hameleon2x\Llm\Dto\Message;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\ToolCall;
use Hameleon2x\Llm\Dto\ToolDefinition;
use Hameleon2x\Llm\Enum\Role;
use Hameleon2x\Llm\Factory\ToolCallFactory;

/**
 * Движок агентского цикла LLM: запрос к модели → исполнение tool-calls → повтор,
 * пока модель не вернёт финальный ответ или не упрётся в лимиты.
 *
 * Не привязан к БД, конкретному помощнику и UI: историю, реестр тулз, базовый системный промт
 * и реакцию на события цикла передаёт вызывающий код.
 *
 * Пример (без БД, без сохранения истории):
 * ```php
 * $runner = new Runner($client);
 * $result = $runner->run(
 *     $messages,                  // Message[]
 *     $toolbox,                   // ToolboxInterface
 *     fn() => 'Системный промт',  // callable
 *     new Config()
 * );
 * echo $result->content;
 * ```
 */
class Runner
{
    private Client $llm;
    private SystemPromptComposer $promptComposer;

    public function __construct(Client $llm)
    {
        $this->llm = $llm;
        $this->promptComposer = new SystemPromptComposer();
    }

    /**
     * Прогнать агентский цикл.
     *
     * @param Message[]        $messages       история диалога без system-сообщения
     * @param callable         $systemPromptFn function(Message[] $history): string — базовый системный промт
     * @param callable|null    $emit           function(string $event, string $content, array $meta): void —
     *                                            реакция на события цикла (Event::*); null — без реакции
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

        $tools = $toolbox->definitions();
        $toolCallsLeft = $config->maxToolCalls;
        $usage = new Usage();

        // Возобновление прерванного хода. В истории мог остаться ассистентский ход с tool_call'ами
        // без ответов: suspend-тулза ждёт ответа пользователя, либо прогон оборвался посреди исполнения.
        // Дорешиваем эти вызовы тем же путём, что и обычный ход: обычные тулзы исполняем, suspend —
        // снова пауза. Если ответы уже подставлены (целая история) — здесь пусто, идём прямо в цикл.
        $pending = $this->findUnansweredToolCalls($messages);
        if ($pending !== []) {
            $outcome = $this->executeToolCalls($pending, $toolbox, $messages, $toolCallsLeft, $emit);
            if ($outcome['suspendedIds'] !== []) {
                return Result::suspended(
                    $outcome['suspendedIds'],
                    $messages,
                    0,
                    $config->maxToolCalls - $toolCallsLeft,
                    $usage
                );
            }
        }

        for ($turn = 0; $turn < $config->maxTurns; $turn++) {
            $turnsUsed = $turn + 1;
            $toolCallsUsed = $config->maxToolCalls - $toolCallsLeft;

            // Системный промт перестраивается каждый оборот: в него попадают пояснения
            // по тулзам, которые уже вызывались в истории.
            $systemPrompt = $this->buildSystemPrompt($systemPromptFn, $messages, $toolbox);

            $request = $this->buildRequest($systemPrompt, $messages, $tools, $config);
            $resp = $this->llm->execute($request);
            $usage->add($resp);

            if (!$resp->isSuccess()) {
                return Result::error(
                    $resp->error ?? 'Ошибка вызова LLM',
                    $messages,
                    $turnsUsed,
                    $toolCallsUsed,
                    $usage
                );
            }

            if (!$resp->hasToolCalls()) {
                $content = trim($resp->content ?? '');
                $answer = $content !== '' ? $content : 'Нет ответа от модели.';
                $messages[] = Message::assistant($answer);
                return Result::success($answer, $messages, $turnsUsed, $toolCallsUsed, $usage);
            }

            $assistantToolCalls = array_map(
                static fn(ToolCall $tc) => ToolCallFactory::toArray($tc),
                $resp->toolCalls
            );
            $emit(Event::ASSISTANT_MESSAGE, $resp->content ?? '', ['tool_calls' => $assistantToolCalls]);

            // Порядок для API: assistant с tool_calls — сразу перед сообщениями tool.
            $messages[] = Message::assistant($resp->content ?? '', $assistantToolCalls);

            // TOOL_CALL — событие «модель запросила вызов»; эмитим один раз здесь, на получении хода
            // (по всем вызовам сразу). executeToolCalls шлёт только TOOL_RESULT, поэтому добор
            // неотвеченных вызовов при возобновлении не порождает повторных TOOL_CALL.
            foreach ($resp->toolCalls as $toolCall) {
                $emit(Event::TOOL_CALL, $toolCall->getFunctionName(), [
                    'tool_call_id' => $toolCall->id,
                    'tool'         => $toolCall->getFunctionName(),
                    'args'         => $toolCall->getArguments(),
                ]);
            }

            $outcome = $this->executeToolCalls($resp->toolCalls, $toolbox, $messages, $toolCallsLeft, $emit);

            if ($outcome['suspendedIds'] !== []) {
                // В ходе есть приостановленные вызовы — останавливаем прогон. Внешний код предоставит
                // их результаты (ответы пользователя) и возобновит цикл, когда закрыты ВСЕ из них.
                return Result::suspended(
                    $outcome['suspendedIds'],
                    $messages,
                    $turnsUsed,
                    $config->maxToolCalls - $toolCallsLeft,
                    $usage
                );
            }

            if ($outcome['limitExhausted']) {
                return $this->finishOnToolLimit($messages, $systemPromptFn, $toolbox, $config, $turnsUsed, $usage);
            }
        }

        $messages[] = Message::assistant($config->turnsExhaustedText);
        return Result::success(
            $config->turnsExhaustedText,
            $messages,
            $config->maxTurns,
            $config->maxToolCalls - $toolCallsLeft,
            $usage
        );
    }

    /**
     * Базовый промт + аугментация описаниями уже вызванных тулз.
     *
     * @param Message[] $messages
     */
    private function buildSystemPrompt(callable $systemPromptFn, array $messages, ToolboxInterface $toolbox): string
    {
        $basePrompt = (string)$systemPromptFn($messages);
        return $this->promptComposer->compose($basePrompt, $messages, $toolbox);
    }

    /**
     * @param Message[]        $messages
     * @param ToolDefinition[] $tools
     */
    private function buildRequest(string $systemPrompt, array $messages, array $tools, Config $config): Request
    {
        $messagesWithSystem = array_merge([Message::system($systemPrompt)], $messages);
        $request = Request::withTools($messagesWithSystem, $tools, $config->toolChoice);
        $this->applyGenerationParams($request, $config);
        if ($config->plugins !== null) {
            $request->setPlugins($config->plugins);
        }
        return $request;
    }

    /**
     * При исчерпании лимита tool-calls добавляем сообщение-добивку и просим итоговый ответ без тулз.
     *
     * @param Message[] $messages
     */
    private function finishOnToolLimit(
        array            $messages,
        callable         $systemPromptFn,
        ToolboxInterface $toolbox,
        Config           $config,
        int              $turnsUsed,
        Usage            $usage
    ): Result {
        $toolCallsUsed = $config->maxToolCalls;

        $messages[] = Message::user($config->limitNudgeMessage);

        $systemPrompt = $this->buildSystemPrompt($systemPromptFn, $messages, $toolbox);
        $messagesWithSystem = array_merge([Message::system($systemPrompt)], $messages);

        $request = Request::messages($messagesWithSystem);
        $this->applyGenerationParams($request, $config);

        $resp = $this->llm->execute($request);
        $usage->add($resp);
        if ($resp->isSuccess() && trim($resp->content ?? '') !== '') {
            $messages[] = Message::assistant($resp->content);
            return Result::success($resp->content, $messages, $turnsUsed, $toolCallsUsed, $usage);
        }

        $messages[] = Message::assistant($config->limitFallbackText);
        return Result::success($config->limitFallbackText, $messages, $turnsUsed, $toolCallsUsed, $usage);
    }

    private function applyGenerationParams(Request $request, Config $config): void
    {
        if ($config->temperature !== null) {
            $request->setTemperature($config->temperature);
        }
        if ($config->maxTokens !== null) {
            $request->setMaxTokens($config->maxTokens);
        }
        if ($config->extraParams !== null) {
            $request->setExtraParams($config->extraParams);
        }
    }

    /**
     * Исполнить набор tool-вызовов: обычную тулзу — выполнить и дописать tool-сообщение в $messages;
     * suspend-тулзу — собрать её id (tool-сообщение не пишем, результат придёт извне). Расходует бюджет
     * $toolCallsLeft; при его исчерпании оставшиеся вызовы закрываются tool-ошибкой (ход остаётся
     * полностью отвечён) и возвращается limitExhausted=true.
     *
     * Эмитит только TOOL_RESULT. Событие TOOL_CALL шлёт вызывающий код один раз — на получении хода
     * от модели, поэтому добор неотвеченных вызовов при возобновлении повторных TOOL_CALL не порождает.
     *
     * Единый путь исполнения и для обычного хода, и для добора неотвеченных вызовов при возобновлении.
     *
     * @param ToolCall[] $toolCalls
     * @param Message[]  $messages       дописывается tool-сообщениями (по ссылке)
     * @param int        $toolCallsLeft  остаток бюджета вызовов (по ссылке)
     * @return array{suspendedIds: string[], limitExhausted: bool}
     */
    private function executeToolCalls(
        array            $toolCalls,
        ToolboxInterface $toolbox,
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
                // Бюджет вызовов исчерпан: оставшиеся вызовы закрываем tool-ошибкой, чтобы ход остался
                // полностью отвечён. Иначе завершённый ход повис бы без ответов и сломал и следующий
                // запрос (правило протокола), и логику возобновления (см. findUnansweredToolCalls).
                $content = json_encode(
                    ['error' => 'Достигнут лимит вызовов инструментов за прогон.'],
                    JSON_UNESCAPED_UNICODE
                );
                $emit(Event::TOOL_RESULT, $content, [
                    'tool_call_id' => $toolCall->id,
                    'tool'         => $toolCall->getFunctionName(),
                    'ok'           => false,
                ]);
                $messages[] = Message::tool($toolCall->id, $content);
                continue;
            }

            $toolCallsLeft--;

            $toolName = $toolCall->getFunctionName();
            $args = $toolCall->getArguments();

            $result = $toolbox->execute($toolName, $args);

            if ($result->isSuspended()) {
                // Suspend-тулза (human-in-the-loop): результат предоставит внешний код позже.
                // tool-сообщение сейчас не пишем, только копим id вызова — закрыть нужно ВСЕ
                // приостановленные вызовы перед следующим assistant.
                $suspendedIds[] = $toolCall->id;
                continue;
            }

            $content = json_encode($result->toJsonArray(), JSON_UNESCAPED_UNICODE);

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
     * Все tool-вызовы истории, оставшиеся без ответного tool-сообщения. Возникают при возобновлении:
     * suspend ждёт ответа пользователя, либо прогон оборвался посреди исполнения тулз — их нужно
     * дорешить, прежде чем снова обращаться к модели.
     *
     * Простая разница множеств (все вызовы минус все ответы) безопасна, потому что держится инвариант
     * «завершённый ход всегда полностью отвечён»: обычные ходы закрываются перед следующим assistant,
     * а ход, обрезанный лимитом вызовов, закрывается tool-ошибками в executeToolCalls. Значит без
     * ответа может остаться только текущий незавершённый ход — его и дорешиваем (дописывая в хвост).
     *
     * В истории tool_calls лежат как массивы (формат API) — восстанавливаем в ToolCall.
     *
     * @param Message[] $messages
     * @return ToolCall[] неотвеченные вызовы (пустой массив — дорешивать нечего)
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
}
