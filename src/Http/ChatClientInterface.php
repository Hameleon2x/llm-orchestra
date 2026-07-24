<?php

namespace Hameleon2x\Llm\Http;

use Hameleon2x\Llm\Exception\LlmException;

/**
 * Транспорт до OpenAI-совместимого Chat Completions API: запрос → сырое тело ответа.
 *
 * Отдельный интерфейс нужен, чтобы подменить cURL на клиент приложения (PSR-18, прокси, запись
 * фикстур в тестах), не трогая провайдера.
 */
interface ChatClientInterface
{
    /**
     * POST на эндпоинт chat completions.
     *
     * @param array                 $payload тело запроса
     * @param array<string, string> $headers дополнительные заголовки поверх обязательных
     * @param int|null              $timeout таймаут запроса в секундах; null — таймаут клиента
     * @return string сырое тело ответа
     * @throws LlmException при сбое транспорта или ответе с кодом ошибки
     */
    public function chat(array $payload, array $headers = [], ?int $timeout = null): string;
}
