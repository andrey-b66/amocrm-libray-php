<?php

declare(strict_types=1);

namespace Amocrm\Exception;

use RuntimeException;

/** Ошибка выполнения запроса к amoCRM API. */
class ApiException extends RuntimeException
{
    private int $statusCode;
    private string $httpMethod;
    private string $endpoint;
    private array $responseData;

    public function __construct(
        string $message,
        int $statusCode,
        string $httpMethod,
        string $endpoint,
        array $responseData = []
    ) {
        parent::__construct($message, $statusCode);

        $this->statusCode = $statusCode;
        $this->httpMethod = $httpMethod;
        $this->endpoint = $endpoint;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHttpMethod(): string
    {
        return $this->httpMethod;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getResponseData(): array
    {
        return $this->responseData;
    }

    public function getValidationErrors(): array
    {
        $errors = $this->responseData['validation-errors'] ?? [];

        return is_array($errors) ? $errors : [];
    }
}
