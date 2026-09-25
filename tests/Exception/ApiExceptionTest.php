<?php

declare(strict_types=1);

namespace Amocrm\Tests\Exception;

use Amocrm\Exception\ApiException;
use PHPUnit\Framework\TestCase;

/** Исключение хранит детали запроса и ответа amoCRM. */
final class ApiExceptionTest extends TestCase
{
    public function testKeepsRequestDetails(): void
    {
        $exception = new ApiException('Ошибка', 400, 'PATCH', 'api/v4/leads', ['detail' => 'Ошибка']);

        self::assertSame('Ошибка', $exception->getMessage());
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame(400, $exception->getCode());
        self::assertSame('PATCH', $exception->getHttpMethod());
        self::assertSame('api/v4/leads', $exception->getEndpoint());
        self::assertSame(['detail' => 'Ошибка'], $exception->getResponseData());
    }

    public function testResponseDataIsEmptyByDefault(): void
    {
        $exception = new ApiException('Нет связи', 0, 'GET', 'api/v4/leads');

        self::assertSame([], $exception->getResponseData());
        self::assertSame([], $exception->getValidationErrors());
        self::assertSame('', $exception->getRequestId());
    }

    public function testKeepsRequestId(): void
    {
        $exception = new ApiException('Ошибка', 400, 'POST', 'api/v4/leads', [], 'a614076704b6a3a3c4a8f15eca606239');

        self::assertSame('a614076704b6a3a3c4a8f15eca606239', $exception->getRequestId());
    }

    public function testReturnsValidationErrors(): void
    {
        $errors = [['request_id' => '0', 'errors' => [['code' => 'NotSupportedChoice', 'path' => 'status_id']]]];
        $exception = new ApiException('Ошибка', 400, 'POST', 'api/v4/leads', ['validation-errors' => $errors]);

        self::assertSame($errors, $exception->getValidationErrors());
    }

    public function testIgnoresMalformedValidationErrors(): void
    {
        $exception = new ApiException('Ошибка', 400, 'POST', 'api/v4/leads', ['validation-errors' => 'oops']);

        self::assertSame([], $exception->getValidationErrors());
    }
}
