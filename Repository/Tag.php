<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\EntityType;

/**
 * Репозиторий справочников тегов и тегов конкретных сущностей.
 *
 * Первым аргументом методы принимают `leads`, `contacts` или `companies`.
 */
final class Tag
{
    private const MAX_PAGE_SIZE = 250;

    private ApiClient $request;

    public function __construct(ApiClient $apiClient)
    {
        $this->request = $apiClient;
    }

    /**
     * Получить страницу тегов из справочника.
     *
     * Пример: find('leads', 'query=Важная заявка')
     */
    public function find(
        string $entityType,
        string $query = '',
        int $page = 1,
        int $limit = self::MAX_PAGE_SIZE,
    ): array {
        $entityType = EntityType::validate($entityType);

        $query = trim(trim($query), '&');
        $query = ($query === '' ? '' : "$query&") . "page=$page&limit=$limit";

        $response = $this->request->get("api/v4/$entityType/tags", $query);

        return array_values($response['_embedded']['tags'] ?? []);
    }

    /**
     * Создать тег или получить существующий тег с тем же названием.
     *
     * Пример: create('leads', ['name' => 'Важная заявка'])
     */
    public function create(string $entityType, array $data): array
    {
        $entityType = EntityType::validate($entityType);

        $response = $this->request->post("api/v4/$entityType/tags", [$data]);

        return $response['_embedded']['tags'][0] ?? [];
    }

    /**
     * Добавить теги к сущности, не затрагивая уже назначенные.
     *
     * Тег передаётся как ID, название или сырой массив формата amoCRM.
     *
     * Пример: addToEntity('leads', $leadId, [$tagId, 'Повторный клиент'])
     */
    public function addToEntity(string $entityType, int $entityId, array $tags): array
    {
        return $this->mutateEntityTags($entityType, $entityId, 'tags_to_add', $tags);
    }

    /**
     * Удалить отдельные теги сущности, не затрагивая остальные.
     *
     * Пример: removeFromEntity('leads', $leadId, [$tagId])
     */
    public function removeFromEntity(string $entityType, int $entityId, array $tags): array
    {
        return $this->mutateEntityTags($entityType, $entityId, 'tags_to_delete', $tags);
    }

    /** Удалить у сущности все теги. */
    public function clearForEntity(string $entityType, int $entityId): array
    {
        $entityType = EntityType::validate($entityType);

        return $this->request->patch(
            "api/v4/$entityType/$entityId",
            ['_embedded' => ['tags' => null]],
        );
    }

    private function mutateEntityTags(
        string $entityType,
        int $entityId,
        string $operation,
        array $tags,
    ): array {
        $entityType = EntityType::validate($entityType);

        $tags = array_map(
            static fn (mixed $tag): array => match (true) {
                is_array($tag) => $tag,
                is_int($tag) => ['id' => $tag],
                default => ['name' => (string) $tag],
            },
            $tags,
        );

        return $this->request->patch("api/v4/$entityType/$entityId", [$operation => $tags]);
    }
}
