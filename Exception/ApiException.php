<?php

declare(strict_types=1);

namespace Amocrm\Exception;

use RuntimeException;

/** Ошибка выполнения запроса к amoCRM API. */
class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $httpMethod,
        private readonly string $endpoint,
        private readonly array $responseData = [],
    ) {
        parent::__construct($message, $statusCode);
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

        return is_array($errors) ? array_values($errors) : [];
    }
}
