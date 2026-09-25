<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Exception\ApiException;
use Amocrm\Repository\Call;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

/** Регистрация звонков и разбор отклонённых. */
final class CallTest extends FakeAmocrmTestCase
{
    public function testCreateSendsListAndReturnsRegisteredCalls(): void
    {
        $this->respond(['_embedded' => ['calls' => [
            ['id' => 1, 'entity_id' => 10, 'request_id' => 'a'],
            ['id' => 2, 'entity_id' => 10, 'request_id' => 'b'],
        ]], 'errors' => []]);

        $calls = [
            ['phone' => '+79990000000', 'direction' => Call::DIRECTION_INBOUND, 'request_id' => 'a'],
            ['phone' => '+79990000000', 'direction' => Call::DIRECTION_OUTBOUND, 'request_id' => 'b'],
        ];

        self::assertSame([
            ['id' => 1, 'entity_id' => 10, 'request_id' => 'a'],
            ['id' => 2, 'entity_id' => 10, 'request_id' => 'b'],
        ], $this->calls()->create($calls));
        self::assertSame(['POST /api/v4/calls'], $this->requestLog());
        self::assertSame($calls, $this->lastRequest()['body']);
    }

    public function testCreateRejectsSingleCallInsteadOfList(): void
    {
        $exception = self::exceptionFrom(fn () => $this->calls()->create(['phone' => '+79990000000']));

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame([], $this->requests());
    }

    public function testRejectedCallThrowsWithReason(): void
    {
        // Так живая amoCRM отвечает, когда не принят ни один звонок: 400 без общего `detail`.
        $response = [
            '_total_items' => 0,
            'errors' => [['title' => 'Entity not found', 'status' => 263, 'detail' => 'Entity not found', 'request_id' => '0']],
            '_embedded' => ['calls' => []],
        ];
        $this->respond($response, 400, ['X-Request-Id' => 'request-400']);

        $exception = self::exceptionFrom(fn () => $this->calls()->create([['phone' => '+70000000000']]));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM не приняла звонок. Entity not found', $exception->getMessage());
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('request-400', $exception->getRequestId());
        self::assertSame('POST', $exception->getHttpMethod());
        self::assertSame('api/v4/calls', $exception->getEndpoint());
        self::assertSame($response, $exception->getResponseData());
    }

    public function testRejectedBatchThrowsForAllCalls(): void
    {
        $this->respond(['errors' => [['detail' => 'Entity not found']], '_embedded' => ['calls' => []]], 400);

        $exception = self::exceptionFrom(fn () => $this->calls()->create([
            ['phone' => '+70000000000'],
            ['phone' => '+70000000001'],
        ]));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM не приняла звонки. Entity not found', $exception->getMessage());
    }

    /** @dataProvider foreignErrors */
    public function testOtherErrorsPassThroughUnchanged(int $status, array $body, string $expectedMessage): void
    {
        $this->respond($body, $status);

        $exception = self::exceptionFrom(fn () => $this->calls()->create([['phone' => '+79990000000']]));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame($status, $exception->getStatusCode());
        self::assertSame($expectedMessage, $exception->getMessage());
    }

    public function foreignErrors(): array
    {
        return [
            'ошибка валидации' => [
                400,
                ['title' => 'Bad Request', 'detail' => 'Request validation failed', 'validation-errors' => []],
                'amoCRM отклонила данные запроса. Request validation failed',
            ],
            'токен' => [401, ['detail' => 'Неверный логин или пароль'], 'Токен amoCRM истёк, недействителен или отозван. Неверный логин или пароль'],
        ];
    }

    public function testRejectedCallWithoutReasonThrowsAnyway(): void
    {
        $this->respond([]);

        $exception = self::exceptionFrom(fn () => $this->calls()->create([['phone' => '+79990000000']]));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM не приняла звонок.', $exception->getMessage());
    }

    public function testPartlyRejectedBatchThrowsWithAcceptedCallsInResponse(): void
    {
        $response = [
            '_embedded' => ['calls' => [['id' => 1, 'request_id' => 'a']]],
            'errors' => [['request_id' => 'b', 'detail' => 'Сущность не найдена']],
        ];
        $this->respond($response, 200, ['X-Request-Id' => 'request-200']);

        $exception = self::exceptionFrom(fn () => $this->calls()->create([
            ['phone' => '+79990000000', 'request_id' => 'a'],
            ['phone' => '+70000000000', 'request_id' => 'b'],
        ]));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM приняла не все звонки. Сущность не найдена', $exception->getMessage());
        self::assertSame([['id' => 1, 'request_id' => 'a']], $exception->getResponseData()['_embedded']['calls']);
        // Ответ был успешным, но ID запроса в исключение всё равно попадает.
        self::assertSame('request-200', $exception->getRequestId());
    }

    public function testCreateIncomingSetsDirectionAndReturnsOneCall(): void
    {
        $this->respond(['_embedded' => ['calls' => [['id' => 1]]]]);

        $call = $this->calls()->createIncoming(['phone' => '+79990000000', 'direction' => Call::DIRECTION_OUTBOUND]);

        self::assertSame(['id' => 1], $call);
        self::assertSame([['phone' => '+79990000000', 'direction' => 'inbound']], $this->lastRequest()['body']);
    }

    public function testCreateOutgoingSetsDirectionAndReturnsOneCall(): void
    {
        $this->respond(['_embedded' => ['calls' => [['id' => 1]]]]);

        self::assertSame(['id' => 1], $this->calls()->createOutgoing(['phone' => '+79990000000']));
        self::assertSame('outbound', $this->lastRequest()['body'][0]['direction']);
    }

    private function calls(): Call
    {
        return $this->amocrm()->calls();
    }
}
