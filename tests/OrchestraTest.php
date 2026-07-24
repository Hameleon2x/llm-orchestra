<?php

/** Исполнитель: повторы, переключение моделей, бюджеты времени, журнал попыток. */

use Hameleon2x\Llm\Dto\Request;
use Hameleon2x\Llm\Error\ErrorCategory;
use Hameleon2x\Llm\Orchestra;
use Hameleon2x\Llm\Registry;
use Hameleon2x\Llm\Provider\OpenAiProvider;

/** Сбой провайдера с HTTP-кодом: код в диапазоне статусов трактуется как ответ сервера. */
function serverError(string $message = 'gateway down'): RuntimeException
{
    return new RuntimeException($message, 500);
}

suite('Исполнитель: успех и повторы');

test('успешный ответ несёт ключ модели и расход токенов', static function (): void {
    $client = new FakeChatClient([okBody('привет')]);
    $response = (new Orchestra(catalogOf($client), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm');

    assertTrue($response->isSuccess());
    assertSame('привет', $response->content);
    assertSame('m', $response->modelKey);
    assertSame(15, $response->usage->totalTokens);
    assertCount(1, $response->attempts);
});

test('повтор той же моделью с нарастающей паузой', static function (): void {
    $client = new FakeChatClient([serverError(), serverError(), okBody('со третьей')]);
    $sleeper = new RecordingSleeper();

    $response = (new Orchestra(catalogOf($client, ['defaultPolicy' => ['retries' => 2, 'delay' => 5, 'backoff' => 2]]), null, $sleeper))
        ->execute(Request::simple('system', 'user'), 'm');

    assertTrue($response->isSuccess());
    assertSame(3, $client->calls());
    assertSame([5.0, 10.0], $sleeper->slept);
    assertCount(3, $response->attempts);
    assertSame(3, $response->attempts[0]->maxAttempts, 'в журнале видно, сколько попыток разрешено');
});

test('неповторяемая категория повторов не получает', static function (): void {
    $client = new FakeChatClient([new RuntimeException('bad request', 400)]);
    $response = (new Orchestra(catalogOf($client, ['defaultPolicy' => ['retries' => 3]]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm');

    assertFalse($response->isSuccess());
    assertSame(ErrorCategory::BAD_REQUEST, $response->error->category);
    assertSame(1, $client->calls());
});

suite('Исполнитель: переключение моделей');

test('после исчерпания повторов работа уходит следующей модели цепочки', static function (): void {
    $first = new FakeChatClient([serverError(), serverError()]);
    $second = new FakeChatClient([okBody('ответила запасная')]);

    $response = (new Orchestra(catalogOfTwo($first, $second, ['defaultPolicy' => ['retries' => 1, 'delay' => 0]]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm1');

    assertTrue($response->isSuccess());
    assertSame('m2', $response->modelKey);
    assertSame(2, $first->calls());
    assertSame(1, $second->calls());
    assertCount(3, $response->attempts);
});

test('maxSwitches = 0 запрещает переключение', static function (): void {
    $first = new FakeChatClient([serverError()]);
    $second = new FakeChatClient([okBody('не должна отвечать')]);

    $response = (new Orchestra(catalogOfTwo($first, $second, ['maxSwitches' => 0]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm1');

    assertFalse($response->isSuccess());
    assertSame(0, $second->calls());
});

test('then = stop отменяет переключение', static function (): void {
    $first = new FakeChatClient([serverError()]);
    $second = new FakeChatClient([okBody('не должна отвечать')]);

    $response = (new Orchestra(catalogOfTwo($first, $second, ['defaultPolicy' => ['retries' => 0, 'then' => 'stop']]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm1');

    assertFalse($response->isSuccess());
    assertSame(0, $second->calls());
});

test('ошибка настройки не повторяется и не переключает модель', static function (): void {
    $first = new FakeChatClient([new TypeError('null passed to strlen()')]);
    $second = new FakeChatClient([okBody('не должна отвечать')]);

    $response = (new Orchestra(catalogOfTwo($first, $second, ['defaultPolicy' => ['retries' => 3]]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm1');

    assertSame(ErrorCategory::CONFIG, $response->error->category);
    assertSame(1, $first->calls());
    assertSame(0, $second->calls());
});

test('в ошибке видно, какая модель упала последней', static function (): void {
    $first = new FakeChatClient([serverError()]);
    $second = new FakeChatClient([serverError()]);

    $response = (new Orchestra(catalogOfTwo($first, $second), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm1');

    assertSame('m2', $response->error->modelKey);
    assertSame('p2', $response->error->providerKey);
});

suite('Исполнитель: бюджеты времени');

test('исчерпанный бюджет вызова прекращает перебор', static function (): void {
    $first = new FakeChatClient([serverError()]);
    $second = new FakeChatClient([okBody('не успеет')]);

    $response = (new Orchestra(catalogOfTwo($first, $second, ['maxTotalWaitSeconds' => 0]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm1');

    assertFalse($response->isSuccess());
    assertSame(0, $second->calls(), 'на переключение времени уже нет');
});

test('таймаут запроса поджимается остатком бюджета вызова', static function (): void {
    $client = new FakeChatClient([okBody('ок')]);
    $registry = catalogOf($client, [
        'providers' => ['p' => ['class' => OpenAiProvider::class, 'httpClient' => $client, 'timeout' => 300]],
        'maxTotalWaitSeconds' => 30,
    ]);

    (new Orchestra($registry, null, new RecordingSleeper()))->execute(Request::simple('system', 'user'), 'm');

    assertSame(30, $client->timeouts[0], 'бюджет вызова короче настроенного таймаута');
});

test('таймаут модели уважается, когда бюджета хватает', static function (): void {
    $client = new FakeChatClient([okBody('ок')]);
    $registry = catalogOf($client, [
        'models' => ['m' => ['provider' => 'p', 'name' => 'slug', 'timeout' => 45]],
        'maxTotalWaitSeconds' => null,
    ]);

    (new Orchestra($registry, null, new RecordingSleeper()))->execute(Request::simple('system', 'user'), 'm');

    assertSame(45, $client->timeouts[0]);
});

suite('Исполнитель: устройство');

test('транспорт создаётся один раз на провайдера', static function (): void {
    $created = 0;
    $client = new FakeChatClient([okBody('раз'), okBody('два')]);

    $registry = Registry::fromArray([
        'providers'    => ['p' => [
            'class'      => OpenAiProvider::class,
            'httpClient' => static function () use ($client, &$created) {
                $created++;

                return $client;
            },
        ]],
        'models'       => ['m' => ['provider' => 'p', 'name' => 'slug']],
        'defaultModel' => 'm',
    ]);

    $orchestra = new Orchestra($registry, null, new RecordingSleeper());
    $orchestra->execute(Request::simple('system', 'первый'), 'm');
    $orchestra->execute(Request::simple('system', 'второй'), 'm');

    assertSame(1, $created, 'кеш провайдеров переживает повторные вызовы');
});

test('подмена определения провайдера сбрасывает кеш транспорта', static function (): void {
    $old = new FakeChatClient([okBody('старый')]);
    $new = new FakeChatClient([okBody('новый')]);

    $registry = catalogOf($old);
    $orchestra = new Orchestra($registry, null, new RecordingSleeper());
    $orchestra->execute(Request::simple('system', 'user'), 'm');

    $registry->addProvider(\Hameleon2x\Llm\Config\ProviderDefinition::fromArray('p', [
        'class'      => OpenAiProvider::class,
        'httpClient' => $new,
    ]));

    $response = $orchestra->execute(Request::simple('system', 'user'), 'm');

    assertSame('новый', $response->content, 'после замены записи каталога работает новый транспорт');
    assertSame(1, $old->calls());
});

test('неизвестный ключ модели подменяется моделью по умолчанию', static function (): void {
    $client = new FakeChatClient([okBody('ответ')]);
    $response = (new Orchestra(catalogOf($client), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'нет-такой');

    assertTrue($response->isSuccess());
    assertSame('m', $response->modelKey);
});

test('каталог без модели по умолчанию отдаёт ошибку конфигурации, а не исключение', static function (): void {
    $client = new FakeChatClient([okBody('ответ')]);
    $registry = Registry::fromArray([
        'providers' => ['p' => ['class' => OpenAiProvider::class, 'httpClient' => $client]],
        'models'    => ['m' => ['provider' => 'p', 'name' => 'slug']],
    ]);

    $response = (new Orchestra($registry, null, new RecordingSleeper()))->execute(Request::simple('s', 'u'));

    assertFalse($response->isSuccess());
    assertSame(ErrorCategory::CONFIG, $response->error->category);
    assertSame(0, $client->calls());
});

test('пустой ход модели — ошибка, а не успех с пустым текстом', static function (): void {
    $client = new FakeChatClient([emptyBody(), emptyBody()]);
    $response = (new Orchestra(catalogOf($client, ['defaultPolicy' => ['retries' => 1, 'delay' => 0]]), null, new RecordingSleeper()))
        ->execute(Request::simple('system', 'user'), 'm');

    assertFalse($response->isSuccess());
    assertSame(ErrorCategory::EMPTY_RESPONSE, $response->error->category);
    assertSame(2, $client->calls(), 'категория повторяемая');
});
