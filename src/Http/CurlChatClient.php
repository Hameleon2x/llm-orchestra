<?php

namespace Hameleon2x\Llm\Http;

use RuntimeException;

/**
 * Реализация ChatClientInterface через расширение PHP cURL. Внешних HTTP-зависимостей нет.
 */
class CurlChatClient implements ChatClientInterface
{
    private string $url;
    private string $token;
    private int    $timeout;

    private const DEFAULT_BASE_URL = 'https://api.openai.com';
    private const CHAT_PATH        = '/v1/chat/completions';
    private const DEBUG = false;

    public function __construct(string $token, ?string $baseUrl = null, int $timeout = 300)
    {
        $baseUrl = rtrim($baseUrl ?? self::DEFAULT_BASE_URL, '/');
        $this->url = $baseUrl . self::CHAT_PATH;
        $this->token = $token;
        $this->timeout = $timeout > 0 ? $timeout : 300;
    }

    public function chat(array $params): string
    {
        $params['stream'] = false;
        $bodyJson = json_encode($params, JSON_UNESCAPED_UNICODE);
        $bodyLen = strlen($bodyJson);

        $host = parse_url($this->url, PHP_URL_HOST);
        if (self::DEBUG) {
            echo "[LLM cURL] POST {$host} body={$bodyLen} bytes\n";
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bodyJson,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->timeout),
        ]);

        $t0 = microtime(true);
        $body = curl_exec($ch);
        $elapsed = round(microtime(true) - $t0, 3);

        $errNo = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errNo !== 0) {
            $errMsg = curl_strerror($errNo) ?: 'Unknown cURL error';
            if (self::DEBUG) {
                echo "[LLM cURL] FAIL {$elapsed}s: cURL error {$errNo}: {$errMsg}\n";
            }
            throw new RuntimeException("cURL error {$errNo}: {$errMsg}", $errNo);
        }

        if (self::DEBUG) {
            echo "[LLM cURL] DONE {$httpCode} len=" . strlen($body) . " {$elapsed}s\n";
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("HTTP {$httpCode}: " . substr($body, 0, 500), $httpCode);
        }

        if ($body === '' || $body === false) {
            throw new RuntimeException('Empty response body from API');
        }

        return (string)$body;
    }
}
