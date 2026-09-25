<?php

declare(strict_types=1);

namespace Amocrm\Exception;

use RuntimeException;

/**
 * Ошибка запроса к amoCRM API.
 *
 * Код исключения — HTTP-статус ответа; 0 — запрос до amoCRM не дошёл.
 */
class ApiException extends RuntimeException
{
    private string $httpMethod;
    private string $endpoint;
    private array $responseData;
    private string $requestId;

    /** $responseData — разобранное тело ответа, $requestId — заголовок `X-Request-Id` ответа. */
    public function __construct(
        string $message,
        int $statusCode,
        string $httpMethod,
        string $endpoint,
        array $responseData = [],
        string $requestId = ''
    ) {
        parent::__construct($message, $statusCode);

        $this->httpMethod = $httpMethod;
        $this->endpoint = $endpoint;
        $this->responseData = $responseData;
        $this->requestId = $requestId;
    }

    /** HTTP-статус ответа, то же, что getCode(); 0 — запрос до amoCRM не дошёл. */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    /** Метод запроса: GET, POST или PATCH. */
    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    /** Эндпоинт запроса, например `api/v4/leads`. */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /** Разобранное тело ответа amoCRM; пустое, если ответа не было или он не JSON. */
    public function getResponseData(): array
    {
        return $this->responseData;
    }

    /** ID запроса из заголовка `X-Request-Id` для обращения в поддержку amoCRM; пустая строка, если его нет. */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /** Ошибки валидации из поля `validation-errors` ответа; пустой список, если их нет. */
    public function getValidationErrors(): array
    {
        $errors = $this->responseData['validation-errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
