<?php

namespace Hameleon2x\Llm\Provider;

use Exception;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\Response;
use Hameleon2x\Llm\Dto\ToolCall;
use Hameleon2x\Llm\Exception\LlmProviderException;
use Hameleon2x\Llm\Exception\LlmRateLimitException;
use Hameleon2x\Llm\Exception\LlmValidationException;
use Hameleon2x\Llm\Factory\MessageFactory;
use Hameleon2x\Llm\Factory\ToolDefinitionFactory;
use Hameleon2x\Llm\Http\ChatClientInterface;
use Hameleon2x\Llm\Http\CurlChatClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Провайдер для OpenAI-совместимого Chat Completions API. Базовый класс для
 * OpenRouter и Requesty — они отличаются только дефолтами baseUrl/model и именем.
 */
class OpenAiProvider extends BaseProvider
{
    protected ?ChatClientInterface $client = null;

    /**
     * @param string|null $baseUrl Базовый URL без /v1. null — https://api.openai.com
     */
    public function __construct(
        string           $token,
        string           $model = 'gpt-4o-mini',
        ?string          $baseUrl = null,
        ?float           $temperature = null,
        ?float           $topP = null,
        ?int             $maxTokens = null,
        int              $retryAttempts = 3,
        int              $timeout = 30,
        int              $priority = 999,
        ?array           $supportedModels = null,
        ?LoggerInterface $logger = null
    )
    {
        parent::__construct(
            $token,
            $model,
            $baseUrl,
            $temperature,
            $topP,
            $maxTokens,
            $retryAttempts,
            $timeout,
            $priority,
            $supportedModels,
            $logger
        );
    }

    protected function getClient(): ChatClientInterface
    {
        if ($this->client === null) {
            $this->client = new CurlChatClient(
                $this->token,
                $this->baseUrl ?? null,
                $this->timeout > 0 ? $this->timeout : 30
            );
        }
        return $this->client;
    }

    protected function doExecute(Request $request): Response
    {
        $client = $this->getClient();

        $params = [
            'model'       => $this->getModel($request),
            'messages'    => $this->prepareMessages($request),
            'temperature' => $this->getTemperature($request),
            'top_p'       => $this->getTopP($request),
            'max_tokens'  => $this->getMaxTokens($request),
        ];

        if (!empty($request->tools)) {
            $params['tools'] = $this->prepareTools($request);
            if ($request->toolChoice !== null) {
                $params['tool_choice'] = $request->toolChoice;
            }
        }

        if ($request->seed !== null) {
            $params['seed'] = $request->seed;
        }

        if (!empty($request->plugins)) {
            $params['plugins'] = $request->plugins;
        }

        if (!empty($request->extraParams)) {
            // Стандартные ключи всегда выигрывают — extraParams не может перетереть
            // model/messages/temperature и прочие зарезервированные поля.
            $params = array_merge($request->extraParams, $params);
        }

        try {
            $raw = $client->chat($params);

            if ($raw === false || $raw === '') {
                throw new LlmProviderException('Empty response from API', 0, null, true);
            }

            $response = json_decode($raw);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new LlmProviderException('Invalid JSON response: ' . json_last_error_msg(), 0, null, true);
            }

            // Ошибка API в теле ответа (а не через HTTP-код).
            if (isset($response->error)) {
                $err = $response->error;
                $message = is_object($err) && isset($err->message) ? $err->message : (string)$err;
                $code = is_object($err) && isset($err->code) ? (int)$err->code : 0;
                if ($code === 429) {
                    throw new LlmRateLimitException($message, $code);
                }
                if ($code >= 400 && $code < 500) {
                    throw new LlmValidationException($message, $code);
                }
                throw new LlmProviderException($message, $code, null, true);
            }

            $choices = $response->choices ?? [];
            $choice = $choices[0] ?? null;

            if (!$choice || !isset($choice->message)) {
                throw new LlmProviderException('Empty response from API', 0, null, false);
            }

            $message = $choice->message;
            $content = $message->content ?? null;
            $toolCalls = [];

            if (isset($message->tool_calls) && is_array($message->tool_calls)) {
                foreach ($message->tool_calls as $tc) {
                    $func = $tc->function ?? (object)['name' => '', 'arguments' => '{}'];
                    $toolCalls[] = new ToolCall(
                        $tc->id ?? '',
                        $tc->type ?? 'function',
                        [
                            'name'      => is_object($func) && isset($func->name) ? $func->name : '',
                            'arguments' => is_object($func) && isset($func->arguments) ? $func->arguments : '{}',
                        ]
                    );
                }
            }

            $usage = $response->usage ?? (object)[];
            $metadata = [
                'promptTokens'     => $usage->prompt_tokens ?? 0,
                'completionTokens' => $usage->completion_tokens ?? 0,
                'totalTokens'      => $usage->total_tokens ?? 0,
                'finishReason'     => $choice->finish_reason ?? null,
            ];

            $modelName = $response->model ?? $this->getModel($request);

            return Response::success(
                $this->getName(),
                $modelName,
                $content,
                $toolCalls,
                $metadata
            );
        } catch (LlmRateLimitException $e) {
            throw $e;
        } catch (LlmValidationException $e) {
            throw $e;
        } catch (LlmProviderException $e) {
            throw $e;
        } catch (Exception $e) {
            $message = $e->getMessage();
            $code = (int)$e->getCode();
            if ($code === 429) {
                throw new LlmRateLimitException($message, $code, $e);
            }
            if ($code >= 400 && $code < 500) {
                throw new LlmValidationException($message, $code, $e);
            }
            // cURL error 56 (Failure when receiving data from the peer) — не повторяем
            $retryable = !($code === 56 && strpos($message, 'cURL error') !== false);
            throw new LlmProviderException(
                'OpenAI request failed: ' . $message,
                $code,
                $e,
                $retryable
            );
        } catch (Throwable $e) {
            $code = (int)$e->getCode();
            $retryable = !($code === 56 && strpos($e->getMessage(), 'cURL error') !== false);
            throw new LlmProviderException(
                'OpenAI request failed: ' . $e->getMessage(),
                $code,
                $e,
                $retryable
            );
        }
    }

    protected function prepareMessages(Request $request): array
    {
        $messages = [];
        foreach ($request->messages as $message) {
            $messages[] = MessageFactory::toArray($message);
        }
        return $messages;
    }

    protected function prepareTools(Request $request): array
    {
        $tools = [];
        foreach ($request->tools as $tool) {
            $tools[] = ToolDefinitionFactory::toArray($tool);
        }
        return $tools;
    }

    public function getName(): string
    {
        return 'OpenAI';
    }
}
