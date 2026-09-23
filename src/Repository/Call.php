<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;

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

    private ApiClient $client;

    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
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
        $response = $this->client->post(self::ENDPOINT, [$data]);
        $call = $response['_embedded']['calls'][0] ?? null;

        if ($call === null) {
            // Отклонённый звонок amoCRM кладёт в поле `errors`, а HTTP-код
            // оставляет успешным: так бывает, когда по номеру не нашлось ни
            // контакта, ни сделки. Без исключения это выглядело бы как успех.
            throw new ApiException(
                trim('amoCRM не приняла звонок. ' . self::errorDetail($response)),
                200,
                'POST',
                self::ENDPOINT,
                $response,
            );
        }

        return $call;
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

    /** Достать пояснение из поля `errors` ответа amoCRM. */
    private static function errorDetail(array $response): string
    {
        foreach ($response['errors'] ?? [] as $error) {
            foreach ($error['errors'] ?? [] as $reason) {
                if (is_string($reason['detail'] ?? null)) {
                    return $reason['detail'];
                }
            }

            if (is_string($error['detail'] ?? null)) {
                return $error['detail'];
            }
        }

        return '';
    }
}
