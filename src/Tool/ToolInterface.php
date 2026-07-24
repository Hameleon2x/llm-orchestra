<?php

namespace Hameleon2x\Llm\Tool;

use Hameleon2x\Llm\Tool\Dto\Property;
use Hameleon2x\Llm\Tool\Dto\Result;

/**
 * Контракт тулзы LLM-агента: имя, описание, параметры, исполнение, признак отображения в UI.
 */
interface ToolInterface
{
    /** Имя функции в API (напр. get_weather). */
    public function getName(): string;

    /**
     * Описание для модели в списке tools: когда и зачем вызывать тулзу.
     */
    public function getDescription(): string;

    /**
     * Пояснение к выходу тулзы: структура JSON-ответа, смысл полей, пограничные случаи.
     * Runner подмешивает его в РЕЗУЛЬТАТ тулзы (под ключом firstUseHintKey()) при первом её
     * вызове в диалоге — а не в системный промт, чтобы префикс запроса оставался неизменным
     * и не сбрасывал prompt-кеш провайдера. Вход (параметры) сюда не повторять — он задаётся
     * getParameters(). Пустая строка — без пояснения (ключ в результат не добавляется).
     *
     * Дефолт `''` есть в AbstractTool — переопредели, только если пояснение нужно.
     */
    public function firstUseHint(): string;

    /**
     * Имя ключа, под которым Runner кладёт firstUseHint() в результат первого вызова тулзы.
     * Дефолт AbstractTool::DEFAULT_FIRST_USE_HINT_KEY ('hint_use'); переопредели, если ключ
     * конфликтует с полями результата тулзы.
     */
    public function firstUseHintKey(): string;

    /**
     * @return Property[] свойства для JSON Schema parameters
     */
    public function getParameters(): array;

    /**
     * @param array $args декодированные аргументы от LLM
     */
    public function execute(array $args): Result;

    /**
     * Нужно ли рендерить вызов тулзы в UI чата (виджеты, превью результата).
     *
     * Движку признак не нужен — он для приложения, которое показывает ход диалога. В контракте
     * тулзы он потому, что показывает её именно вызывающий код, а не реестр.
     */
    public function shouldDisplay(array $args): bool;
}
