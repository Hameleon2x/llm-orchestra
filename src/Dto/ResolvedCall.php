<?php

namespace Hameleon2x\Llm\Dto;

use Hameleon2x\Llm\Config\GenerationParams;
use Hameleon2x\Llm\Config\ModelDefinition;
use Hameleon2x\Llm\Config\ProviderDefinition;
use Hameleon2x\Llm\Support\Merge;

/**
 * Готовый к отправке вызов: запрос плюс всё, что дал каталог, — уже слитое.
 *
 * Слияние трёх уровней (каталог → модель → вызов) происходит здесь, а не в провайдере. Провайдеру
 * остаётся собрать payload и разобрать ответ, поэтому написать свой провайдер — дело нескольких
 * десятков строк.
 */
final class ResolvedCall
{
    public Request $request;

    public ModelDefinition $model;

    public ProviderDefinition $provider;

    /** Параметры генерации после слияния всех уровней. */
    public GenerationParams $params;

    /** Дополнительные поля payload после слияния. */
    public array $extraParams;

    /** Заголовки после слияния. */
    public array $headers;

    /** Карта извлечения полей ответа после слияния. */
    public array $capture;

    /** Таймаут запроса, секунды. */
    public int $timeout;

    /** Хранить ли сырой ответ в Response. */
    public bool $keepRaw;

    /**
     * Слить уровни конфигурации в один вызов.
     *
     * Параметры генерации подчиняются явности: каталог → модель → вызов. Список unsupported
     * применяется поверх результата, потому что описывает ограничение модели, а не пожелание.
     */
    public static function build(
        Request            $request,
        ModelDefinition    $model,
        ProviderDefinition $provider,
        GenerationParams   $defaultParams,
        ?int               $timeoutCap = null
    ): self {
        $call = new self();
        $call->request = $request;
        $call->model = $model;
        $call->provider = $provider;

        $call->params = $defaultParams->merge($model->params)->merge($request->params);
        $call->extraParams = Merge::layers($provider->extraParams, $model->extraParams, $request->extraParams);
        $call->headers = Merge::layers($provider->headers, $model->headers, $request->headers);
        $call->capture = Merge::layers($provider->capture, $model->capture);
        $call->timeout = $model->timeout ?? $provider->timeout;
        if ($timeoutCap !== null) {
            // Запрос не должен переживать бюджет вызова: иначе потолок времени обещал бы больше,
            // чем удерживает.
            $call->timeout = max(1, min($call->timeout, $timeoutCap));
        }
        $call->keepRaw = $provider->keepRaw;

        return $call;
    }

    /** Слаг модели для API. */
    public function modelName(): string
    {
        return $this->model->name;
    }

    /** Ключ модели в каталоге. */
    public function modelKey(): string
    {
        return $this->model->key;
    }

    /** Ключ провайдера в каталоге. */
    public function providerKey(): string
    {
        return $this->provider->key;
    }

    /**
     * Параметры генерации в виде полей payload — с вырезанными неподдерживаемыми.
     */
    public function paramsPayload(): array
    {
        return $this->params->toPayload($this->model->unsupported);
    }
}
