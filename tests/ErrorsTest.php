<?php

/** Разбор сбоев: категории по коду cURL, HTTP-статусу, телу ответа и исключению. */

use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Error\ErrorMapper;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;

suite('Ошибки: транспорт');

test('таймаут cURL отличается от прочих сетевых сбоев', static function (): void {
    assertSame(ErrorCategory::TIMEOUT, ErrorMapper::fromCurl(28, 'timed out')->category);
    assertSame(ErrorCategory::NETWORK, ErrorMapper::fromCurl(6, 'could not resolve host')->category);
});

test('ошибки настройки cURL не повторяются', static function (): void {
    foreach ([1, 3, 60, 77] as $errno) {
        $info = ErrorMapper::fromCurl($errno, 'bad setup');
        assertSame(ErrorCategory::CONFIG, $info->category, 'cURL ' . $errno);
        assertFalse($info->retryable);
    }
});

test('код cURL сохраняется как код провайдера', static function (): void {
    assertSame('curl_28', ErrorMapper::fromCurl(28, 'timed out')->providerCode);
});

suite('Ошибки: HTTP-статусы');

test('статусы раскладываются по категориям', static function (): void {
    $cases = [
        408 => ErrorCategory::TIMEOUT,
        413 => ErrorCategory::CONTEXT_LENGTH,
        429 => ErrorCategory::RATE_LIMIT,
        401 => ErrorCategory::AUTH,
        403 => ErrorCategory::AUTH,
        404 => ErrorCategory::MODEL_UNAVAILABLE,
        402 => ErrorCategory::MODEL_UNAVAILABLE,
        400 => ErrorCategory::BAD_REQUEST,
        500 => ErrorCategory::SERVER_ERROR,
        503 => ErrorCategory::SERVER_ERROR,
    ];

    foreach ($cases as $status => $expected) {
        assertSame($expected, ErrorMapper::fromHttpStatus($status)->category, 'HTTP ' . $status);
    }
});

test('редирект — ошибка настройки адреса, а не временный сбой', static function (): void {
    foreach ([301, 302, 307] as $status) {
        $info = ErrorMapper::fromHttpStatus($status, '<html>moved, request timed out</html>');
        assertSame(ErrorCategory::CONFIG, $info->category, 'HTTP ' . $status);
        assertFalse($info->retryable, 'редирект не повторяем');
    }
});

test('текст уточняет категорию у 4xx', static function (): void {
    assertSame(
        ErrorCategory::CONTEXT_LENGTH,
        ErrorMapper::fromHttpStatus(400, '', ['error' => ['message' => 'maximum context length exceeded']])->category
    );
    assertSame(
        ErrorCategory::CONTENT_FILTER,
        ErrorMapper::fromHttpStatus(400, '', ['error' => ['message' => 'blocked by content policy']])->category
    );
});

test('текст не переопределяет 5xx и 429', static function (): void {
    assertSame(
        ErrorCategory::SERVER_ERROR,
        ErrorMapper::fromHttpStatus(500, 'upstream request timed out')->category,
        'слово «таймаут» внутри 500 не делает сбой таймаутом'
    );
    assertSame(
        ErrorCategory::RATE_LIMIT,
        ErrorMapper::fromHttpStatus(429, 'model is overloaded')->category
    );
});

suite('Ошибки: тело ответа и исключения');

test('ошибка в теле успешного ответа разбирается по тексту', static function (): void {
    $info = ErrorMapper::fromPayload(['error' => ['message' => 'rate limit reached', 'code' => 'rate_limit']]);

    assertSame(ErrorCategory::RATE_LIMIT, $info->category);
    assertSame('rate_limit', $info->providerCode);
});

test('тело без поля error ошибкой не считается', static function (): void {
    assertNull(ErrorMapper::fromPayload(['choices' => []]));
});

test('числовой код в теле трактуется как HTTP-статус', static function (): void {
    $info = ErrorMapper::fromPayload(['error' => ['message' => 'no access', 'code' => 403]]);

    assertSame(ErrorCategory::AUTH, $info->category);
    assertSame(403, $info->httpStatus);
});

test('ошибка уровня PHP — это конфигурация, а не временный сбой', static function (): void {
    $info = ErrorMapper::fromThrowable(new TypeError('strlen(): Argument #1 must be of type string'));

    assertSame(ErrorCategory::CONFIG, $info->category);
    assertFalse($info->retryable);
});

test('исключение с HTTP-кодом разбирается как ответ сервера', static function (): void {
    $info = ErrorMapper::fromThrowable(new RuntimeException('service unavailable', 503));

    assertSame(ErrorCategory::SERVER_ERROR, $info->category);
    assertSame(503, $info->httpStatus);
});

suite('Ошибки: поведение категорий');

test('категории связи повторяются, ошибки запроса — нет', static function (): void {
    assertTrue(ErrorCategory::isRetryableByDefault(ErrorCategory::NETWORK));
    assertTrue(ErrorCategory::isRetryableByDefault(ErrorCategory::TIMEOUT));
    assertTrue(ErrorCategory::isRetryableByDefault(ErrorCategory::EMPTY_RESPONSE));
    assertFalse(ErrorCategory::isRetryableByDefault(ErrorCategory::BAD_REQUEST));
    assertFalse(ErrorCategory::isRetryableByDefault(ErrorCategory::AUTH));
});

test('на чужую модель не уходят запрос-ошибка, модерация, срок и конфиг', static function (): void {
    assertFalse(ErrorCategory::isFallbackableByDefault(ErrorCategory::BAD_REQUEST));
    assertFalse(ErrorCategory::isFallbackableByDefault(ErrorCategory::CONTENT_FILTER));
    assertFalse(ErrorCategory::isFallbackableByDefault(ErrorCategory::DEADLINE));
    assertFalse(ErrorCategory::isFallbackableByDefault(ErrorCategory::CONFIG));
    assertTrue(ErrorCategory::isFallbackableByDefault(ErrorCategory::SERVER_ERROR));
});

test('список категорий закрыт и знает свои значения', static function (): void {
    assertCount(14, ErrorCategory::all());
    assertTrue(ErrorCategory::isKnown(ErrorCategory::RATE_LIMIT));
    assertFalse(ErrorCategory::isKnown('ratelimit'));
});

suite('Провайдер: адрес и разбор ответа');

test('адрес эндпоинта собирается из baseUrl без дублей слеша', static function (): void {
    $cases = [
        null                     => 'https://api.openai.com/v1/chat/completions',
        'https://gw.local'       => 'https://gw.local/v1/chat/completions',
        'https://gw.local/'      => 'https://gw.local/v1/chat/completions',
        'https://gw.local/proxy' => 'https://gw.local/proxy/v1/chat/completions',
    ];

    foreach ($cases as $baseUrl => $expected) {
        $client = new FakeChatClient([okBody('ок')]);
        $config = ['class' => OpenAiProvider::class, 'httpClient' => $client];
        if ($baseUrl !== null && $baseUrl !== '') {
            $config['baseUrl'] = $baseUrl;
        }

        $registry = Registry::fromArray([
            'providers'    => ['p' => $config],
            'models'       => ['m' => ['provider' => 'p', 'name' => 'slug']],
            'defaultModel' => 'm',
        ]);

        $provider = $registry->provider('p');
        $method = new ReflectionMethod(OpenAiProvider::class, 'endpointUrl');
        $method->setAccessible(true);
        $instance = new OpenAiProvider($provider);

        assertSame($expected, $method->invoke($instance), (string)$baseUrl);
    }
});

test('дополнительные поля ответа достаются картой capture', static function (): void {
    $body = json_encode([
        'choices'           => [[
            'message'       => ['content' => 'ответ', 'reasoning' => 'ход мысли'],
            'finish_reason' => 'stop',
        ]],
        'system_fingerprint' => 'fp_42',
    ]);

    $client = new FakeChatClient([$body]);
    $response = (new \Hameleon2x\Llm\Orchestra(catalogOf($client), null, new RecordingSleeper()))
        ->execute(\Hameleon2x\Llm\Dto\Request::simple('s', 'u'), 'm');

    assertSame('ход мысли', $response->extra('reasoning'));
    assertSame('fp_42', $response->extra('systemFingerprint'));
    assertSame('stop', $response->finishReason());
});

test('сырой ответ доступен по пути, когда его просили сохранить', static function (): void {
    $client = new FakeChatClient([okBody('ответ')]);
    $registry = catalogOf($client, [
        'providers' => ['p' => ['class' => OpenAiProvider::class, 'httpClient' => $client, 'keepRaw' => true]],
    ]);

    $response = (new \Hameleon2x\Llm\Orchestra($registry, null, new RecordingSleeper()))
        ->execute(\Hameleon2x\Llm\Dto\Request::simple('s', 'u'), 'm');

    assertSame('ответ', $response->raw('choices.0.message.content'));
});
