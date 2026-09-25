<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use Amocrm\Support\ApiWriter;

/**
 * Регистрация звонков. Контакт, компанию или сделку для звонка amoCRM находит
 * сама по номеру `phone`; звонок на номер, которого нет в базе, не добавляется.
 */
final class Call
{
    /** Входящий звонок — значение поля `direction`. */
    public const DIRECTION_INBOUND = 'inbound';

    /** Исходящий звонок — значение поля `direction`. */
    public const DIRECTION_OUTBOUND = 'outbound';

    private const ENDPOINT = 'api/v4/calls';

    private ApiClient $client;

    /** Обычно репозиторий берут у фасада: $amocrm->calls(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Зарегистрировать звонки: принимает список и возвращает список. Обязательны
     * `phone`, `direction`, `duration` и `source`.
     *
     * Если amoCRM отклонила хоть один звонок, бросается ApiException: принятые —
     * в getResponseData()['_embedded']['calls'], отклонённые — в ['errors'].
     *
     * Пример: create([[
     *     'phone' => '+79990000000',
     *     'direction' => Call::DIRECTION_INBOUND,
     *     'duration' => 125,
     *     'source' => 'my-telephony',
     *     'uniq' => 'call-2026-0001',
     *     'call_status' => 4, // 4 — разговор состоялся; всего статусов 7
     * ]])
     */
    public function create(array $calls): array
    {
        $created = [];

        foreach (ApiWriter::batches($calls) as $batch) {
            $response = $this->send($batch);
            $accepted = $response['_embedded']['calls'] ?? [];

            // Если принята только часть звонков, отклонённые amoCRM кладёт в `errors`,
            // а HTTP-код оставляет успешным. Без исключения это выглядело бы успехом.
            if (count($accepted) < count($batch)) {
                throw self::rejected($batch, $response, 200, $this->client->lastRequestId());
            }

            foreach ($accepted as $call) {
                $created[] = $call;
            }
        }

        return $created;
    }

    /** Зарегистрировать один входящий звонок: `direction` проставляется сам. */
    public function createIncoming(array $data): array
    {
        $data['direction'] = self::DIRECTION_INBOUND;

        return $this->create([$data])[0];
    }

    /** Зарегистрировать один исходящий звонок: `direction` проставляется сам. */
    public function createOutgoing(array $data): array
    {
        $data['direction'] = self::DIRECTION_OUTBOUND;

        return $this->create([$data])[0];
    }

    /**
     * Отправить порцию звонков. Если не принят ни один, amoCRM отвечает HTTP 400
     * с тем же `errors`, но без общего `detail` — причину достаёт rejected().
     */
    private function send(array $batch): array
    {
        try {
            return $this->client->post(self::ENDPOINT, $batch);
        } catch (ApiException $exception) {
            $response = $exception->getResponseData();

            if ($exception->getStatusCode() !== 400 || !isset($response['errors'])) {
                throw $exception;
            }

            throw self::rejected($batch, $response, 400, $exception->getRequestId());
        }
    }

    /** Исключение об отклонённых звонках с первым пояснением amoCRM. */
    private static function rejected(array $batch, array $response, int $statusCode, string $requestId): ApiException
    {
        if (($response['_embedded']['calls'] ?? []) !== []) {
            $message = 'amoCRM приняла не все звонки.';
        } else {
            $message = count($batch) === 1 ? 'amoCRM не приняла звонок.' : 'amoCRM не приняла звонки.';
        }

        return new ApiException(
            trim($message . ' ' . self::errorDetail($response)),
            $statusCode,
            'POST',
            self::ENDPOINT,
            $response,
            $requestId,
        );
    }

    /** Первое пояснение `detail` из поля `errors` ответа amoCRM или пустая строка. */
    private static function errorDetail(array $response): string
    {
        foreach ($response['errors'] ?? [] as $error) {
            if (is_string($error['detail'] ?? null)) {
                return $error['detail'];
            }
        }

        return '';
    }
}
