<?php

namespace Hameleon2x\Llm\Error;

use Throwable;

/**
 * Приведение сбоев провайдера к категориям ErrorCategory.
 *
 * Это единственное место в библиотеке, где разбираются коды cURL, HTTP-статусы и тексты ошибок.
 * Всё остальное — и библиотека, и приложение — работает уже с категорией, поэтому нестабильные
 * формулировки провайдеров никуда дальше не протекают. Свой провайдер тоже пользуется этим
 * маппером, чтобы не изобретать классификацию заново.
 */
final class ErrorMapper
{
    /** Коды cURL, означающие срыв по времени. */
    private const CURL_TIMEOUT = [28];

    /**
     * Коды cURL, которые означают неверную настройку, а не временный сбой: битый адрес, неизвестный
     * протокол, проблема с сертификатами. Повторять их и перебирать модели бессмысленно.
     */
    private const CURL_CONFIG = [1, 3, 60, 77];

    /**
     * Маркеры в тексте ошибки. Порядок важен: более специфичные категории проверяются раньше.
     */
    private const TEXT_MARKERS = [
        ErrorCategory::CONTEXT_LENGTH => [
            'context length', 'context_length', 'maximum context', 'context window',
            'too many tokens', 'prompt is too long', 'reduce the length', 'input length',
        ],
        ErrorCategory::CONTENT_FILTER => [
            'content_filter', 'content policy', 'content_policy', 'moderation', 'flagged', 'safety',
        ],
        ErrorCategory::RATE_LIMIT => [
            'rate limit', 'rate_limit', 'too many requests', 'quota',
        ],
        ErrorCategory::AUTH => [
            'api key', 'api_key', 'unauthorized', 'authentication', 'invalid token', 'permission denied',
        ],
        ErrorCategory::MODEL_UNAVAILABLE => [
            'model not found', 'no endpoints', 'does not exist', 'no allowed providers',
            'model is not available', 'deprecated', 'overloaded',
        ],
        ErrorCategory::TIMEOUT => [
            'timeout', 'timed out',
        ],
    ];

    /**
     * Сбой транспорта cURL.
     */
    public static function fromCurl(int $errno, string $message): ErrorInfo
    {
        if (in_array($errno, self::CURL_TIMEOUT, true)) {
            $category = ErrorCategory::TIMEOUT;
        } elseif (in_array($errno, self::CURL_CONFIG, true)) {
            $category = ErrorCategory::CONFIG;
        } else {
            $category = ErrorCategory::NETWORK;
        }

        $info = new ErrorInfo($category, $message);
        $info->providerCode = 'curl_' . $errno;

        return $info;
    }

    /**
     * Ответ с HTTP-кодом ошибки. $payload — разобранное тело, если его удалось разобрать:
     * из него берутся точное сообщение и машинный код провайдера.
     */
    public static function fromHttpStatus(int $status, string $body = '', ?array $payload = null): ErrorInfo
    {
        $error = self::extractPayloadError($payload);
        $message = $error['message'] ?? self::trimBody($body);
        $text = $message . ' ' . ($error['code'] ?? '') . ' ' . ($error['type'] ?? '');

        $category = self::categoryFromStatus($status);

        // Точные категории почти всегда видно только по тексту: HTTP 400 приезжает и на переполненный
        // контекст, и на блокировку модерацией, и на настоящую ошибку в запросе.
        $byText = self::categoryFromText($text);
        if ($byText !== null && self::textOverridesStatus($status, $byText)) {
            $category = $byText;
        }

        $info = new ErrorInfo($category, $message !== '' ? $message : ('HTTP ' . $status));
        $info->httpStatus = $status;
        $info->providerCode = $error['code'] ?? null;
        $info->raw = is_array($payload) ? $payload : [];

        return $info;
    }

    /**
     * Ошибка, пришедшая в теле успешного ответа (часть шлюзов отдаёт HTTP 200 с полем `error`).
     * Возвращает null, если поля `error` в теле нет.
     */
    public static function fromPayload(array $payload): ?ErrorInfo
    {
        if (!isset($payload['error'])) {
            return null;
        }

        $error = self::extractPayloadError($payload);
        $message = $error['message'] ?? 'Provider returned an error';
        $status = $error['status'];

        if ($status !== null) {
            $info = self::fromHttpStatus($status, '', $payload);
            $info->message = $message;

            return $info;
        }

        $category = self::categoryFromText($message . ' ' . ($error['code'] ?? '') . ' ' . ($error['type'] ?? ''))
            ?? ErrorCategory::SERVER_ERROR;

        $info = new ErrorInfo($category, $message);
        $info->providerCode = $error['code'] ?? null;
        $info->raw = $payload;

        return $info;
    }

    /**
     * Произвольное исключение — в том числе от чужого HTTP-клиента, подставленного вместо cURL.
     * Код исключения в диапазоне HTTP-статусов трактуется как статус ответа, иначе категорию
     * определяет текст.
     */
    public static function fromThrowable(Throwable $e): ErrorInfo
    {
        // Ошибка уровня PHP (TypeError, деление на ноль, обращение к null) — это баг в коде, а не
        // временный сбой. Повторять её и перебирать из-за неё модели бессмысленно: результат будет
        // тот же, а счёт вырастет.
        if ($e instanceof \Error) {
            $info = new ErrorInfo(ErrorCategory::CONFIG, get_class($e) . ': ' . $e->getMessage(), false);
            $info->exception = $e;

            return $info;
        }

        $code = (int)$e->getCode();

        if ($code >= 400 && $code < 600) {
            $info = self::fromHttpStatus($code, $e->getMessage());
            $info->message = $e->getMessage();
            $info->exception = $e;

            return $info;
        }

        $info = new ErrorInfo(self::categoryFromText($e->getMessage()) ?? ErrorCategory::UNKNOWN, $e->getMessage());
        $info->exception = $e;

        return $info;
    }

    /**
     * Категория по HTTP-коду — грубая рамка, которую при необходимости уточняет текст.
     */
    private static function categoryFromStatus(int $status): string
    {
        if ($status === 408) {
            return ErrorCategory::TIMEOUT;
        }
        if ($status === 413) {
            return ErrorCategory::CONTEXT_LENGTH;
        }
        if ($status === 429) {
            return ErrorCategory::RATE_LIMIT;
        }
        if ($status === 401 || $status === 403) {
            return ErrorCategory::AUTH;
        }
        if ($status === 404) {
            return ErrorCategory::MODEL_UNAVAILABLE;
        }
        if ($status >= 500) {
            return ErrorCategory::SERVER_ERROR;
        }
        if ($status >= 400) {
            return ErrorCategory::BAD_REQUEST;
        }
        if ($status >= 300) {
            // Редирект в ответ на POST: адрес провайдера указан не тем, чем надо (схема, лишний путь,
            // переехавший шлюз). Транспорт редиректы не проходит намеренно — повторять нечего.
            return ErrorCategory::CONFIG;
        }

        return ErrorCategory::UNKNOWN;
    }

    /**
     * Уточнять ли категорию по тексту. Для 4xx это нужно (400 приезжает на что угодно), а вот
     * 5xx и 429 сами по себе однозначны — текст «timeout» внутри 500 не должен превращать
     * серверный сбой в таймаут. По той же причине слово «timeout» не переопределяет ни один
     * ответ с кодом ошибки: пришедший статус говорит о запросе больше, чем формулировка.
     */
    private static function textOverridesStatus(int $status, string $byText): bool
    {
        if ($status >= 500 || $status === 429) {
            return false;
        }
        // Тело редиректа — обычно HTML страницы-заглушки, случайное слово в нём не должно
        // перебивать вывод «адрес указан неверно».
        if ($status >= 300 && $status < 400) {
            return false;
        }

        return $byText !== ErrorCategory::TIMEOUT || $status < 400;
    }

    /**
     * Категория по маркерам в тексте ошибки или null, если ничего не опознано.
     */
    private static function categoryFromText(string $text): ?string
    {
        $text = mb_strtolower($text);
        if (trim($text) === '') {
            return null;
        }

        foreach (self::TEXT_MARKERS as $category => $markers) {
            foreach ($markers as $marker) {
                if (strpos($text, $marker) !== false) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Поля ошибки из тела ответа. Формат `{"error": {"message", "type", "code", "status"}}` —
     * общий для OpenAI-совместимых API, но часть шлюзов кладёт туда строку или числовой код.
     *
     * @return array{message: ?string, code: ?string, type: ?string, status: ?int}
     */
    private static function extractPayloadError(?array $payload): array
    {
        $empty = ['message' => null, 'code' => null, 'type' => null, 'status' => null];

        if ($payload === null || !isset($payload['error'])) {
            return $empty;
        }

        $error = $payload['error'];

        if (is_string($error)) {
            return ['message' => $error, 'code' => null, 'type' => null, 'status' => null];
        }

        if (!is_array($error)) {
            return $empty;
        }

        $code = $error['code'] ?? null;
        $status = null;
        if (is_int($code) && $code >= 100 && $code < 600) {
            $status = $code;
            $code = null;
        }
        if (isset($error['status']) && is_int($error['status'])) {
            $status = $error['status'];
        }

        return [
            'message' => isset($error['message']) ? (string)$error['message'] : null,
            'code'    => $code !== null ? (string)$code : null,
            'type'    => isset($error['type']) ? (string)$error['type'] : null,
            'status'  => $status,
        ];
    }

    /**
     * Тело ответа для сообщения об ошибке — обрезанное, чтобы не тащить в лог мегабайты.
     */
    private static function trimBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        return mb_substr($body, 0, 500);
    }
}
