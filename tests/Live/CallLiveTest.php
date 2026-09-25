<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

use Amocrm\Exception\ApiException;
use Amocrm\Repository\Call;
use Throwable;

/**
 * Звонки на живом аккаунте: amoCRM сама находит контакт прогона по телефону
 * и показывает звонок примечанием `call_in` или `call_out`.
 */
final class CallLiveTest extends LiveTestCase
{
    /** Номер, которого точно нет в базе: amoCRM ищет по последним 10 цифрам. */
    private const UNKNOWN_PHONE = '+70000000000';

    public function testIncomingCallLandsOnContactFoundByPhone(): void
    {
        $uniq = 'autotest-in-' . self::runToken();

        $call = $this->untilAccepted(fn () => $this->amocrm()->calls()->createIncoming($this->callData($uniq)));

        self::assertIsInt($call['id'] ?? null);
        self::assertContains($uniq, $this->callUniqs($call, 'call_in'), 'Звонок виден примечанием call_in.');
    }

    public function testOutgoingCallLandsOnContactFoundByPhone(): void
    {
        $uniq = 'autotest-out-' . self::runToken();

        $call = $this->untilAccepted(fn () => $this->amocrm()->calls()->createOutgoing($this->callData($uniq)));

        self::assertContains($uniq, $this->callUniqs($call, 'call_out'), 'Звонок виден примечанием call_out.');
    }

    public function testCreateRegistersBatchAndKeepsRequestIds(): void
    {
        $calls = $this->untilAccepted(fn () => $this->amocrm()->calls()->create([
            $this->callData('autotest-batch-in-' . self::runToken(), Call::DIRECTION_INBOUND, 'in'),
            $this->callData('autotest-batch-out-' . self::runToken(), Call::DIRECTION_OUTBOUND, 'out'),
        ]));

        self::assertEqualsCanonicalizing(['in', 'out'], array_column($calls, 'request_id'));
    }

    public function testCallToUnknownNumberIsRejected(): void
    {
        $data = ['phone' => self::UNKNOWN_PHONE] + $this->callData('autotest-unknown-' . self::runToken());

        $exception = self::exceptionFrom(fn () => $this->amocrm()->calls()->createIncoming($data));

        // Когда не принят ни один звонок, amoCRM отвечает 400, а причину кладёт в `errors`.
        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame('amoCRM не приняла звонок. Entity not found', $exception->getMessage());
        self::assertSame([], $exception->getResponseData()['_embedded']['calls'] ?? null);
    }

    public function testPartlyRejectedBatchReportsAcceptedCalls(): void
    {
        // Сначала убедиться, что номер контакта уже в поиске: иначе отклонятся оба звонка.
        $this->untilAccepted(fn () => $this->amocrm()->calls()->createIncoming(
            $this->callData('autotest-warmup-' . self::runToken()),
        ));

        $exception = self::exceptionFrom(fn () => $this->amocrm()->calls()->create([
            $this->callData('autotest-known-' . self::runToken(), Call::DIRECTION_INBOUND, 'known'),
            ['phone' => self::UNKNOWN_PHONE]
                + $this->callData('autotest-unknown-batch-' . self::runToken(), Call::DIRECTION_INBOUND, 'unknown'),
        ]));

        // При частичном отказе HTTP-код успешный, отклонённые — в `errors`.
        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame(200, $exception->getStatusCode());
        self::assertSame('amoCRM приняла не все звонки. Entity not found', $exception->getMessage());
        self::assertSame(
            ['known'],
            array_column($exception->getResponseData()['_embedded']['calls'] ?? [], 'request_id'),
        );
    }

    /** Данные звонка на телефон контакта этого прогона. */
    private function callData(
        string $uniq,
        string $direction = Call::DIRECTION_INBOUND,
        ?string $requestId = null
    ): array {
        $this->contactId();

        $data = [
            'phone' => self::runPhone(),
            'uniq' => $uniq,
            'source' => 'autotest',
            'duration' => 30,
            'direction' => $direction,
            'call_status' => 4,
            'call_result' => self::MARK,
        ];

        if ($requestId !== null) {
            $data['request_id'] = $requestId;
        }

        return $data;
    }

    /**
     * Повторять регистрацию, пока amoCRM не найдёт контакт: новый номер попадает в
     * поиск не сразу. Отклонённый звонок не создаётся, поэтому дублей не будет.
     */
    private function untilAccepted(callable $register): array
    {
        return self::eventually(
            $register,
            static fn (Throwable $exception): bool => $exception instanceof ApiException
                && isset($exception->getResponseData()['errors']),
        );
    }

    /**
     * `uniq` звонков из примечаний сущности, в которую лёг звонок. Звонок ложится
     * в карточку контакта, а если у контакта одна открытая сделка — в неё.
     */
    private function callUniqs(array $call, string $noteType): array
    {
        $entityType = ($call['entity_type'] ?? '') === 'lead' ? 'leads' : 'contacts';
        $expectedId = $entityType === 'leads' ? $this->leadId() : $this->contactId();

        self::assertSame($expectedId, $call['entity_id'] ?? null, "Звонок лёг в сущность прогона ($entityType).");

        $notes = $this->amocrm()->notes()->findForEntity(
            $entityType,
            $expectedId,
            "filter[note_type][0]=$noteType",
            250,
            null,
        );

        return array_column(array_column($notes, 'params'), 'uniq');
    }
}
