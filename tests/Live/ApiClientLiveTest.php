<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

use Amocrm\Exception\ApiException;
use Amocrm\Facade\Amocrm;

/** Низкоуровневые запросы raw() и HTTP-ошибки на живом аккаунте. */
final class ApiClientLiveTest extends LiveTestCase
{
    public function testRawGetReadsAccount(): void
    {
        $account = $this->amocrm()->raw()->get('api/v4/account');

        self::assertIsInt($account['id'] ?? null);
        self::assertStringContainsString($account['subdomain'] ?? '-', strtolower(self::domain()));
    }

    public function testRawPatchChangesLead(): void
    {
        $price = random_int(10000, 99999);

        $this->amocrm()->raw()->patch('api/v4/leads/' . $this->leadId(), ['price' => $price]);

        self::assertSame($price, $this->amocrm()->leads()->findById($this->leadId())['price'] ?? null);
    }

    public function testInvalidTokenComesAs401(): void
    {
        $exception = self::exceptionFrom(
            fn () => (new Amocrm(self::domain(), 'invalid-token'))->raw()->get('api/v4/account'),
        );

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame(401, $exception->getStatusCode());
        self::assertStringStartsWith('Токен amoCRM истёк, недействителен или отозван.', $exception->getMessage());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $exception->getRequestId(), 'amoCRM присылает X-Request-Id.');
    }

    public function testUnknownEndpointComesAs404(): void
    {
        $exception = self::exceptionFrom(fn () => $this->amocrm()->raw()->get('api/v4/no-such-endpoint'));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame(404, $exception->getStatusCode());
    }
}
