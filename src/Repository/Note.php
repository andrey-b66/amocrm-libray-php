<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;
use Amocrm\Support\ApiWriter;
use Amocrm\Support\EntityType;

/**
 * Репозиторий примечаний контактов, сделок и компаний.
 *
 * Первым аргументом методы принимают `leads`, `contacts` или `companies`.
 */
final class Note
{
    private ApiClient $client;

    /** Обычно репозиторий берут у фасада: $amocrm->notes(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Создать примечания: принимает список и возвращает список. У каждого —
     * `entity_id`, `note_type` и `params`; порции по 250, как у create() репозиториев.
     *
     * Пример: create('leads', [['entity_id' => $leadId, 'note_type' => 'common', 'params' => ['text' => 'Перезвонить']]])
     */
    public function create(string $entityType, array $notes): array
    {
        $entityType = EntityType::validate($entityType);

        return ApiWriter::write($this->client, 'post', "api/v4/$entityType/notes", 'notes', $notes);
    }

    /**
     * Создать одно текстовое примечание и вернуть его.
     *
     * Пример: createCommon('leads', $leadId, 'Клиент просил перезвонить')
     */
    public function createCommon(string $entityType, int $entityId, string $text): array
    {
        return $this->create($entityType, [[
            'entity_id' => $entityId,
            'note_type' => 'common',
            'params' => ['text' => trim($text)],
        ]])[0] ?? [];
    }

    /**
     * Получить примечания сущности: по умолчанию первую страницу (до 250), с $pages = null — все.
     *
     * Пример: findForEntity('leads', $leadId, 'filter[note_type][0]=common', 250, null)
     *
     * @param int|null $pages сколько страниц прочитать; null — все
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

    /** Найти примечание по ID; null, если примечания нет. */
    public function findById(string $entityType, int $entityId, int $noteId): ?array
    {
        $entityType = EntityType::validate($entityType);

        return ApiReader::one($this->client, "api/v4/$entityType/$entityId/notes/$noteId");
    }

    /**
     * Обновить примечания: принимает список и возвращает список. amoCRM требует
     * у каждого `id`, `entity_id`, `note_type` и `params`.
     *
     * Пример: update('leads', [['id' => $noteId, 'entity_id' => $leadId, 'note_type' => 'common', 'params' => ['text' => 'Новый']]])
     */
    public function update(string $entityType, array $notes): array
    {
        $entityType = EntityType::validate($entityType);

        return ApiWriter::write($this->client, 'patch', "api/v4/$entityType/notes", 'notes', $notes);
    }
}
