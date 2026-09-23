<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Exception\ApiException;
use Amocrm\Repository\Call;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;

final class CallTest extends FakeAmocrmTestCase
{
    public function testCreateRegistersCall(): void
    {
        $this->respond(['_embedded' => ['calls' => [['id' => 1, 'entity_id' => 10]]]]);

        $call = $this->calls()->create(['phone' => '+79990000000', 'direction' => Call::DIRECTION_INBOUND]);

        self::assertSame(['id' => 1, 'entity_id' => 10], $call);
        self::assertSame(['POST /api/v4/calls'], $this->requestLog());
        self::assertSame([['phone' => '+79990000000', 'direction' => 'inbound']], $this->lastRequest()['body']);
    }

    public function testRejectedCallThrowsWithReason(): void
    {
        // amoCRM отвечает 200, а отклонённый звонок кладёт в `errors`.
        $response = [
            '_embedded' => ['calls' => []],
            'errors' => [['request_id' => '0', 'errors' => [['detail' => 'Сущность не найдена']]]],
        ];
        $this->respond($response);

        $exception = self::exceptionFrom(fn () => $this->calls()->create(['phone' => '+79990000000']));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM не приняла звонок. Сущность не найдена', $exception->getMessage());
        self::assertSame(200, $exception->getStatusCode());
        self::assertSame('POST', $exception->getHttpMethod());
        self::assertSame('api/v4/calls', $exception->getEndpoint());
        self::assertSame($response, $exception->getResponseData());
    }

    public function testRejectedCallWithoutReasonThrowsAnyway(): void
    {
        $this->respond([]);

        $exception = self::exceptionFrom(fn () => $this->calls()->create(['phone' => '+79990000000']));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM не приняла звонок.', $exception->getMessage());
    }

    public function testCreateIncomingSetsDirection(): void
    {
        $this->respond(['_embedded' => ['calls' => [['id' => 1]]]]);

        $this->calls()->createIncoming(['phone' => '+79990000000', 'direction' => Call::DIRECTION_OUTBOUND]);

        self::assertSame('inbound', $this->lastRequest()['body'][0]['direction']);
    }

    public function testCreateOutgoingSetsDirection(): void
    {
        $this->respond(['_embedded' => ['calls' => [['id' => 1]]]]);

        $this->calls()->createOutgoing(['phone' => '+79990000000']);

        self::assertSame('outbound', $this->lastRequest()['body'][0]['direction']);
    }

    private function calls(): Call
    {
        return $this->amocrm()->calls();
    }
}
