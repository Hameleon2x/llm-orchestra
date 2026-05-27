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

            $limitExhausted = false;
            foreach ($resp->toolCalls as $toolCall) {
                if ($toolCallsLeft <= 0) {
                    $limitExhausted = true;
                    break;
                }
                $toolCallsLeft--;

                $toolName = $toolCall->getFunctionName();
                $args = $toolCall->getArguments();

                $emit(Event::TOOL_CALL, $toolName, [
                    'tool_call_id' => $toolCall->id,
                    'tool'         => $toolName,
                    'args'         => $args,
                ]);

                $result = $toolbox->execute($toolName, $args);
                $content = json_encode($result->toJsonArray(), JSON_UNESCAPED_UNICODE);

                $emit(Event::TOOL_RESULT, $content, [
                    'tool_call_id' => $toolCall->id,
                    'tool'         => $toolName,
                    'ok'           => $result->ok,
                ]);

                $messages[] = Message::tool($toolCall->id, $content);
            }

            if ($limitExhausted) {
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
}
