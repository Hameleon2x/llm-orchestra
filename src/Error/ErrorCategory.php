<?php

namespace Hameleon2x\Llm\Error;

/**
 * Категории сбоев LLM-вызова. Категория — единственный способ отличать ошибки в вызывающем коде:
 * сообщения провайдеров нестабильны и сравнивать их по подстроке нельзя.
 *
 * Здесь же поведение по умолчанию: какие категории имеет смысл повторять той же моделью, а какие —
 * передавать следующей модели цепочки. Конкретная политика может это переопределить.
 */
final class ErrorCategory
{
    /** Сеть недоступна, соединение оборвалось, DNS. */
    public const NETWORK = 'network';

    /** Истёк таймаут запроса. */
    public const TIMEOUT = 'timeout';

    /** Модель вернула ход без текста и без вызовов инструментов — отвечать нечем. */
    public const EMPTY_RESPONSE = 'empty_response';

    /** Превышен лимит запросов (HTTP 429). */
    public const RATE_LIMIT = 'rate_limit';

    /** Ошибка на стороне провайдера (HTTP 5xx). */
    public const SERVER_ERROR = 'server_error';

    /** Ответ пришёл, но разобрать его нельзя: битый JSON, неожиданная структура. */
    public const INVALID_RESPONSE = 'invalid_response';

    /** Модель недоступна: нет такой у провайдера, снята с обслуживания, перегружена. */
    public const MODEL_UNAVAILABLE = 'model_unavailable';

    /** Запрос не помещается в контекстное окно модели. */
    public const CONTEXT_LENGTH = 'context_length';

    /** Запрос или ответ заблокирован модерацией провайдера. */
    public const CONTENT_FILTER = 'content_filter';

    /** Отказ авторизации: неверный или отозванный токен, нет доступа к модели (HTTP 401/403). */
    public const AUTH = 'auth';

    /** Запрос сформирован неверно (HTTP 400/422) — повтор и смена модели не помогут. */
    public const BAD_REQUEST = 'bad_request';

    /** Исчерпан отведённый на работу срок (deadline прогона). */
    public const DEADLINE = 'deadline';

    /** Ошибка конфигурации каталога: несуществующая модель, провайдер, ключ цепочки. */
    public const CONFIG = 'config';

    /** Ничего из перечисленного. */
    public const UNKNOWN = 'unknown';

    /**
     * Категории, которые по умолчанию повторяются той же моделью: сбой связи или временный отказ.
     */
    private const RETRYABLE = [
        self::NETWORK,
        self::TIMEOUT,
        self::EMPTY_RESPONSE,
        self::RATE_LIMIT,
        self::SERVER_ERROR,
        self::INVALID_RESPONSE,
        self::UNKNOWN,
    ];

    /**
     * Категории, при которых по умолчанию НЕ имеет смысла передавать работу следующей модели:
     * запрос неверен сам по себе, работа отменена или конфиг сломан.
     */
    private const NOT_FALLBACKABLE = [
        self::BAD_REQUEST,
        self::CONTENT_FILTER,
        self::DEADLINE,
        self::CONFIG,
    ];

    /**
     * Сбой связи с сервером ИИ: одна и та же реакция на разные симптомы — повторить запрос.
     * Вынесено сюда, чтобы приложения не собирали этот набор у себя.
     */
    private const CONNECTION_DROP = [
        self::NETWORK,
        self::TIMEOUT,
        self::EMPTY_RESPONSE,
    ];

    /**
     * Все категории — чтобы проверять значения из конфига и перечислять допустимые в сообщении
     * об ошибке.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::NETWORK,
            self::TIMEOUT,
            self::EMPTY_RESPONSE,
            self::RATE_LIMIT,
            self::SERVER_ERROR,
            self::INVALID_RESPONSE,
            self::MODEL_UNAVAILABLE,
            self::CONTEXT_LENGTH,
            self::CONTENT_FILTER,
            self::AUTH,
            self::BAD_REQUEST,
            self::DEADLINE,
            self::CONFIG,
            self::UNKNOWN,
        ];
    }

    /**
     * Есть ли такая категория.
     */
    public static function isKnown(string $category): bool
    {
        return in_array($category, self::all(), true);
    }

    /**
     * Повторять ли эту категорию той же моделью (поведение по умолчанию).
     */
    public static function isRetryableByDefault(string $category): bool
    {
        return in_array($category, self::RETRYABLE, true);
    }

    /**
     * Передавать ли работу следующей модели цепочки (поведение по умолчанию).
     */
    public static function isFallbackableByDefault(string $category): bool
    {
        return !in_array($category, self::NOT_FALLBACKABLE, true);
    }

    /**
     * Симптом обрыва связи с сервером ИИ (сеть, таймаут, пустой ход).
     */
    public static function isConnectionDrop(string $category): bool
    {
        return in_array($category, self::CONNECTION_DROP, true);
    }
}
