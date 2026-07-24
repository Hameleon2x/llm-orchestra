<?php

/** Каталог: сборка, проверки конфига, слияние настроек, выбор политики. */

use Hameleon2x\Llm\Config\ErrorPolicy;
use Hameleon2x\Llm\Config\GenerationParams;
use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Dto\ResolvedCall;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Exception\LlmConfigException;
use Hameleon2x\Llm\Provider\OpenAiProvider;
use Hameleon2x\Llm\Registry;

suite('Каталог: сборка и выбор модели');

test('модель находится по ключу каталога', static function (): void {
    $registry = catalogOf(new FakeChatClient());

    assertTrue($registry->has('m'));
    assertSame('model-slug', $registry->model('m')->name);
    assertSame('m', $registry->defaultModelKey());
});

test('неизвестный ключ приводится к модели по умолчанию', static function (): void {
    $registry = catalogOf(new FakeChatClient());

    assertSame('m', $registry->normalize('нет-такой'));
    assertSame('m', $registry->normalize(null));
    assertNull($registry->findModel('нет-такой'));
});

test('каталог без провайдеров и моделей не собирается', static function (): void {
    assertThrows(LlmConfigException::class, static fn() => Registry::fromArray([]));
});

test('модель со ссылкой на чужой провайдер не проходит', static function (): void {
    assertThrows(LlmConfigException::class, static fn() => Registry::fromArray([
        'providers' => ['p' => ['class' => OpenAiProvider::class]],
        'models'    => ['m' => ['provider' => 'нет-такого', 'name' => 'slug']],
    ]));
});

test('модели цепочки фолбэка обязаны быть в каталоге', static function (): void {
    $message = assertThrows(LlmConfigException::class, static fn() => catalogOf(new FakeChatClient(), [
        'fallback' => ['m', 'опечатка'],
    ]));

    assertContains('опечатка', $message);
});

test('модель по умолчанию обязана быть в каталоге', static function (): void {
    assertThrows(LlmConfigException::class, static fn() => catalogOf(new FakeChatClient(), [
        'defaultModel' => 'нет-такой',
    ]));
});

suite('Каталог: проверка значений конфига');

test('then принимает только stop и fallback', static function (): void {
    $message = assertThrows(LlmConfigException::class, static fn() => catalogOf(new FakeChatClient(), [
        'defaultPolicy' => ['then' => 'Stop'],
    ]));
    assertContains('then', $message);

    assertSame(ErrorPolicy::THEN_STOP, catalogOf(new FakeChatClient(), [
        'defaultPolicy' => ['then' => 'stop'],
    ])->defaultPolicy()->then);
});

test('категории в retryOn, stopOn и perCategory сверяются со списком', static function (): void {
    foreach (
        [
            ['retryOn' => ['ratelimit']],
            ['stopOn' => ['bad-request']],
            ['perCategory' => ['rate_limits' => ['retries' => 1]]],
        ] as $policy
    ) {
        assertThrows(LlmConfigException::class, static fn() => catalogOf(new FakeChatClient(), [
            'defaultPolicy' => $policy,
        ]));
    }

    $policy = catalogOf(new FakeChatClient(), [
        'defaultPolicy' => [
            'retryOn'     => [ErrorCategory::TIMEOUT, ErrorCategory::RATE_LIMIT],
            'stopOn'      => [ErrorCategory::BAD_REQUEST],
            'perCategory' => [ErrorCategory::RATE_LIMIT => ['retries' => 3]],
        ],
    ])->defaultPolicy();

    assertCount(2, $policy->retryOn);
    assertSame(4, $policy->maxAttemptsFor(ErrorCategory::RATE_LIMIT));
});

test('имена в unsupported сверяются с параметрами генерации', static function (): void {
    $message = assertThrows(LlmConfigException::class, static fn() => catalogOf(new FakeChatClient(), [
        'models' => ['m' => ['provider' => 'p', 'name' => 'slug', 'unsupported' => ['maxtokens']]],
    ]));
    assertContains('unsupported', $message);

    $model = catalogOf(new FakeChatClient(), [
        'models' => ['m' => ['provider' => 'p', 'name' => 'slug', 'unsupported' => ['top_p', 'temperature']]],
    ])->model('m');

    assertSame(['top_p', 'temperature'], $model->unsupported);
});

suite('Каталог: потолки времени');

test('потолок вызова по умолчанию конечный, явный null его снимает', static function (): void {
    assertSame(600.0, catalogOf(new FakeChatClient())->maxTotalWaitSeconds());
    assertNull(catalogOf(new FakeChatClient(), ['maxTotalWaitSeconds' => null])->maxTotalWaitSeconds());
    assertSame(900.0, catalogOf(new FakeChatClient(), ['maxTotalWaitSeconds' => 900])->maxTotalWaitSeconds());
});

test('срок прогона по умолчанию не задан', static function (): void {
    assertNull(catalogOf(new FakeChatClient())->defaultDeadlineSeconds());
    assertSame(120.0, catalogOf(new FakeChatClient(), ['defaultDeadlineSeconds' => 120])->defaultDeadlineSeconds());
});

test('число переключений по умолчанию — два', static function (): void {
    assertSame(2, catalogOf(new FakeChatClient())->maxSwitches());
    assertSame(0, catalogOf(new FakeChatClient(), ['maxSwitches' => 0])->maxSwitches());
});

suite('Каталог: слияние настроек вызова');

test('параметры генерации сливаются по явности: каталог → модель → вызов', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'defaultParams' => ['temperature' => 0.7, 'maxTokens' => 1024],
        'models'        => ['m' => ['provider' => 'p', 'name' => 'slug', 'params' => ['temperature' => 0.2]]],
    ]);

    $request = Request::messages([]);
    $request->setMaxTokens(8000);

    $call = ResolvedCall::build(
        $request,
        $registry->model('m'),
        $registry->providerOf($registry->model('m')),
        $registry->defaultParams(),
        null
    );

    $payload = $call->paramsPayload();
    assertSame(0.2, $payload['temperature'], 'параметр модели сильнее каталожного');
    assertSame(8000, $payload['max_tokens'], 'параметр вызова сильнее модели и каталога');
});

test('unsupported вырезает параметр, кто бы его ни задал', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'defaultParams' => ['temperature' => 0.7],
        'models'        => ['m' => ['provider' => 'p', 'name' => 'slug', 'unsupported' => ['temperature', 'top_p']]],
    ]);

    $request = Request::messages([]);
    $request->setTemperature(0.9);
    $request->setTopP(0.5);
    $request->setMaxTokens(100);

    $payload = ResolvedCall::build(
        $request,
        $registry->model('m'),
        $registry->providerOf($registry->model('m')),
        $registry->defaultParams(),
        null
    )->paramsPayload();

    assertSame(['max_tokens' => 100], $payload);
});

test('заголовки и extraParams складываются: провайдер → модель → вызов', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'providers' => ['p' => [
            'class'       => OpenAiProvider::class,
            'httpClient'  => new FakeChatClient(),
            'headers'     => ['X-Origin' => 'provider', 'X-Provider' => 'yes'],
            'extraParams' => ['route' => 'provider', 'plugins' => ['a']],
        ]],
        'models'    => ['m' => [
            'provider'    => 'p',
            'name'        => 'slug',
            'headers'     => ['X-Origin' => 'model'],
            'extraParams' => ['route' => 'model'],
        ]],
    ]);

    $request = Request::messages([]);
    $request->setHeaders(['X-Origin' => 'call']);
    $request->setExtraParams(['session_id' => 'run-1']);

    $call = ResolvedCall::build(
        $request,
        $registry->model('m'),
        $registry->providerOf($registry->model('m')),
        $registry->defaultParams(),
        null
    );

    assertSame('call', $call->headers['X-Origin'], 'заголовок вызова сильнее');
    assertSame('yes', $call->headers['X-Provider'], 'заголовок провайдера сохраняется');
    assertSame('model', $call->extraParams['route'], 'поле модели сильнее провайдерского');
    assertSame('run-1', $call->extraParams['session_id']);
    assertSame(['a'], $call->extraParams['plugins'], 'нетронутое поле провайдера остаётся');
});

suite('Каталог: политика ошибок');

test('политика берётся с ближайшего уровня и действует целиком', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'defaultPolicy' => ['retries' => 5, 'delay' => 30, 'perCategory' => [ErrorCategory::TIMEOUT => ['retries' => 9]]],
        'models'        => ['m' => ['provider' => 'p', 'name' => 'slug', 'policy' => ['retries' => 1]]],
    ]);

    $policy = $registry->policyFor($registry->model('m'));

    assertSame(1, $policy->retries, 'взята политика модели');
    assertSame(5.0, $policy->delay, 'незаданное поле — дефолт класса, а не каталога');
    assertSame([], $policy->perCategory, 'правила категорий с другого уровня не подмешиваются');
});

test('модель без своей политики берёт провайдерскую, а не каталожную', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'providers'     => ['p' => [
            'class'      => OpenAiProvider::class,
            'httpClient' => new FakeChatClient(),
            'policy'     => ['retries' => 4],
        ]],
        'defaultPolicy' => ['retries' => 7],
    ]);

    assertSame(4, $registry->policyFor($registry->model('m'))->retries);
});

test('пауза растёт с множителем и упирается в потолок', static function (): void {
    $policy = ErrorPolicy::fromArray(['delay' => 5, 'backoff' => 2, 'maxDelay' => 12]);

    assertSame(5.0, $policy->delayFor(ErrorCategory::NETWORK, 1));
    assertSame(10.0, $policy->delayFor(ErrorCategory::NETWORK, 2));
    assertSame(12.0, $policy->delayFor(ErrorCategory::NETWORK, 3), 'потолок паузы');
});

test('число попыток учитывает переопределение по категории', static function (): void {
    $policy = ErrorPolicy::fromArray([
        'retries'     => 2,
        'perCategory' => [ErrorCategory::RATE_LIMIT => ['retries' => 5]],
    ]);

    assertSame(3, $policy->maxAttemptsFor(null));
    assertSame(6, $policy->maxAttemptsFor(ErrorCategory::RATE_LIMIT));
    assertSame(3, $policy->maxAttemptsFor(ErrorCategory::NETWORK));
});

suite('Каталог: справочные данные');

test('оценка стоимости считается по ценам каталога', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'models' => ['m' => [
            'provider' => 'p',
            'name'     => 'slug',
            'pricing'  => ['in' => 1.0, 'out' => 2.0],   // за миллион токенов
        ]],
    ]);

    assertSame(3.0, $registry->costOf('m', 1000000, 1000000));
    assertNull($registry->costOf('нет-такой', 10, 10));
});

test('подписи моделей отдаются для селекта', static function (): void {
    $registry = catalogOf(new FakeChatClient(), [
        'models' => ['m' => ['provider' => 'p', 'name' => 'slug', 'fullName' => 'Модель М']],
    ]);

    assertSame(['m' => 'Модель М'], $registry->labels());
});

test('параметры генерации знают свои имена', static function (): void {
    assertTrue(GenerationParams::isKnownName('topP'));
    assertTrue(GenerationParams::isKnownName('top_p'));
    assertFalse(GenerationParams::isKnownName('top-p'));
});
