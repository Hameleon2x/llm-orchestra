<?php

namespace Hameleon2x\Llm\Http;

use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorMapper;
use Hameleon2x\Llm\Exception\LlmException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Транспорт на расширении cURL. Внешних HTTP-зависимостей у пакета нет.
 *
 * Про формат запроса не знает ничего: адрес эндпоинта, поля payload и разбор ответа — дело
 * провайдера. Здесь только отправка и приведение HTTP-сбоев к категориям: код cURL и статус
 * разбирает ErrorMapper, наружу уходит LlmException с готовым ErrorInfo.
 */
final class CurlChatClient implements ChatClientInterface
{
    private string $url;
    private string $token;
    private int    $timeout;
    private bool   $debug;

    private LoggerInterface $logger;

    /**
     * @param string $url полный адрес эндпоинта, включая путь
     */
    public function __construct(
        string           $url,
        string           $token,
        int              $timeout = 120,
        bool             $debug = false,
        ?LoggerInterface $logger = null
    ) {
        $this->url = $url;
        $this->token = $token;
        $this->timeout = $timeout > 0 ? $timeout : 120;
        $this->debug = $debug;
        $this->logger = $logger ?? new NullLogger();
    }

    public function chat(array $payload, array $headers = [], ?int $timeout = null): string
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            // Обычно это битая кодировка в сообщениях. Без явной ошибки провайдер получил бы пустое
            // тело и ответил невнятным HTTP 400.
            throw LlmException::of(
                ErrorCategory::BAD_REQUEST,
                'Запрос не сериализуется в JSON: ' . json_last_error_msg()
            );
        }
        $timeout = $timeout !== null && $timeout > 0 ? $timeout : $this->timeout;

        if ($this->debug) {
            $this->logger->debug('LLM request', ['url' => $this->url, 'payload' => $payload]);
        }

        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $this->buildHeaders($headers),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(30, $timeout),
        ]);

        $responseBody = curl_exec($curl);
        $errno = curl_errno($curl);
        $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($errno !== 0) {
            $message = curl_strerror($errno) ?: 'Unknown cURL error';

            throw new LlmException(ErrorMapper::fromCurl($errno, "cURL error {$errno}: {$message}"));
        }

        $responseBody = (string)$responseBody;

        if ($this->debug) {
            $this->logger->debug('LLM response', ['status' => $httpCode, 'body' => $responseBody]);
        }

        // Редиректы (3xx) не проходим: POST при переходе превращается в GET, а ответом на него будет
        // не JSON, и сбой выглядел бы как неразбираемый ответ вместо неверно указанного адреса.
        if ($httpCode >= 300) {
            $payloadDecoded = json_decode($responseBody, true);

            throw new LlmException(ErrorMapper::fromHttpStatus(
                $httpCode,
                $responseBody,
                is_array($payloadDecoded) ? $payloadDecoded : null
            ));
        }

        return $responseBody;
    }

    /**
     * Заголовки запроса: наши значения по умолчанию, поверх них — заголовки провайдера и модели.
     * Авторизация ставится последней, поэтому подменить её конфигом нельзя.
     *
     * @param array<string, string> $extra
     * @return string[]
     */
    private function buildHeaders(array $extra): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        foreach ($extra as $name => $value) {
            if ($value === null) {
                continue;
            }
            $headers[(string)$name] = (string)$value;
        }

        if ($this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $result = [];
        foreach ($headers as $name => $value) {
            $result[] = $name . ': ' . $value;
        }

        return $result;
    }
}
