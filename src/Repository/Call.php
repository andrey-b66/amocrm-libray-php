<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;

/**
 * Репозиторий регистрации звонков в amoCRM.
 *
 * amoCRM самостоятельно ищет связанную сущность по номеру `phone`.
 * Endpoint звонков не принимает прямую привязку через `entity_id`.
 */
final class Call
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    private const ENDPOINT = 'api/v4/calls';

    private ApiClient $request;

    public function __construct(ApiClient $apiClient)
    {
        $this->request = $apiClient;
    }

    /**
     * Зарегистрировать звонок по данным формата amoCRM API v4.
     *
     * Направление задаётся полем `direction`: `inbound` или `outbound`.
     *
     * Пример: create([
     *     'phone' => '+79990000000',
     *     'uniq' => 'call-2026-0001',
     *     'source' => 'my-telephony',
     *     'direction' => Call::DIRECTION_INBOUND,
     *     'duration' => 125,
     *     'call_status' => 4, // 1 сообщение, 2 перезвонить, 3 нет на месте, 4 разговор,
     *     'call_result' => 'Разговор состоялся', // 5 неверный номер, 6 не дозвонился, 7 занято, 8 неизвестно
     *     'link' => 'https://example.test/records/call-2026-0001.mp3',
     * ])
     */
    public function create(array $data): array
    {
        $response = $this->request->post(self::ENDPOINT, [$data]);

        return $response['_embedded']['calls'][0] ?? [];
    }

    /** То же, что create(), но `direction` проставляется сам. */
    public function createIncoming(array $data): array
    {
        $data['direction'] = self::DIRECTION_INBOUND;

        return $this->create($data);
    }

    public function createOutgoing(array $data): array
    {
        $data['direction'] = self::DIRECTION_OUTBOUND;

        return $this->create($data);
    }
}
