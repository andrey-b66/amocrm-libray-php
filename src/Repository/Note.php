<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;
use Amocrm\Support\EntityType;

/**
 * Репозиторий примечаний контактов, сделок и компаний.
 *
 * Первым аргументом методы принимают `leads`, `contacts` или `companies`.
 */
final class Note
{
    private ApiClient $client;

    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Создать примечание любого типа по данным формата amoCRM API v4.
     *
     * Пример: create('leads', $leadId, [
     *     'note_type' => 'call_in',
     *     'params' => ['uniq' => 'call-2026-0001', 'duration' => 60, 'phone' => '+79990000000'],
     * ])
     */
    public function create(string $entityType, int $entityId, array $data): array
    {
        $entityType = EntityType::validate($entityType);
        $data['entity_id'] = $entityId;

        $response = $this->client->post("api/v4/$entityType/notes", [$data]);

        return $response['_embedded']['notes'][0] ?? [];
    }

    /**
     * Создать обычное текстовое примечание.
     *
     * Пример: createCommon('leads', $leadId, 'Клиент просил перезвонить')
     */
    public function createCommon(string $entityType, int $entityId, string $text): array
    {
        return $this->create($entityType, $entityId, [
            'note_type' => 'common',
            'params' => ['text' => trim($text)],
        ]);
    }

    /**
     * Получить примечания конкретной сущности — одну страницу, несколько или все.
     *
     * Запрос и страницы задаются так же, как в find() у репозиториев.
     *
     * Пример: findForEntity('leads', $leadId, 'filter[note_type][0]=common')
     * Пример: findForEntity('leads', $leadId, '', 250, null) — все примечания сущности
     */
    public function findForEntity(
        string $entityType,
        int $entityId,
        string $query = '',
        int $limit = ApiReader::MAX_PAGE_SIZE,
        ?int $pages = 1
    ): array {
        $entityType = EntityType::validate($entityType);

        return ApiReader::pages($this->client, "api/v4/$entityType/$entityId/notes", 'notes', $query, $limit, $pages);
    }

    /** Найти примечание по ID. Возвращает null, если примечания нет. */
    public function findById(string $entityType, int $entityId, int $noteId): ?array
    {
        $entityType = EntityType::validate($entityType);

        return ApiReader::one($this->client, "api/v4/$entityType/$entityId/notes/$noteId");
    }

    /** Обновить примечание частичными данными формата amoCRM API v4. */
    public function update(
        string $entityType,
        int $entityId,
        int $noteId,
        array $data
    ): array {
        $entityType = EntityType::validate($entityType);

        return $this->client->patch("api/v4/$entityType/$entityId/notes/$noteId", $data);
    }
}
