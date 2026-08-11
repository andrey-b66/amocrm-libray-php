<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use Amocrm\Support\EntityType;

/**
 * Репозиторий примечаний контактов, сделок и компаний.
 *
 * Первым аргументом методы принимают `leads`, `contacts` или `companies`.
 */
final class Note
{
    private const MAX_PAGE_SIZE = 250;

    private ApiClient $request;

    public function __construct(ApiClient $apiClient)
    {
        $this->request = $apiClient;
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

        $response = $this->request->post("api/v4/$entityType/notes", [$data]);

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
     * Получить страницу примечаний конкретной сущности.
     *
     * Пример: findForEntity('leads', $leadId, 'filter[note_type][0]=common')
     */
    public function findForEntity(
        string $entityType,
        int $entityId,
        string $query = '',
        int $page = 1,
        int $limit = self::MAX_PAGE_SIZE,
    ): array {
        $entityType = EntityType::validate($entityType);

        $query = trim(trim($query), '&');
        $query = ($query === '' ? '' : "$query&") . "page=$page&limit=$limit";

        $response = $this->request->get("api/v4/$entityType/$entityId/notes", $query);

        return array_values($response['_embedded']['notes'] ?? []);
    }

    /** Найти примечание по ID. Возвращает null, если примечания нет. */
    public function findById(string $entityType, int $entityId, int $noteId): ?array
    {
        $entityType = EntityType::validate($entityType);

        try {
            $note = $this->request->get("api/v4/$entityType/$entityId/notes/$noteId");
        } catch (ApiException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $note === [] ? null : $note;
    }

    /** Обновить примечание частичными данными формата amoCRM API v4. */
    public function update(
        string $entityType,
        int $entityId,
        int $noteId,
        array $data,
    ): array {
        $entityType = EntityType::validate($entityType);

        return $this->request->patch("api/v4/$entityType/$entityId/notes/$noteId", $data);
    }
}
