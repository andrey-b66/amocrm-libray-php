<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;
use Amocrm\Support\EntityType;

/**
 * Репозиторий справочников тегов и тегов конкретных сущностей.
 *
 * Первым аргументом методы принимают `leads`, `contacts` или `companies`.
 */
final class Tag
{
    private ApiClient $client;

    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Получить теги из справочника — одну страницу, несколько или все.
     *
     * Запрос и страницы задаются так же, как в find() у репозиториев.
     *
     * Пример: find('leads', 'query=Важная заявка')
     * Пример: find('leads', '', 250, null) — весь справочник
     */
    public function find(
        string $entityType,
        string $query = '',
        int $limit = ApiReader::MAX_PAGE_SIZE,
        ?int $pages = 1
    ): array {
        $entityType = EntityType::validate($entityType);

        return ApiReader::pages($this->client, "api/v4/$entityType/tags", 'tags', $query, $limit, $pages);
    }

    /**
     * Создать тег или получить существующий тег с тем же названием.
     *
     * Пример: create('leads', ['name' => 'Важная заявка'])
     */
    public function create(string $entityType, array $data): array
    {
        $entityType = EntityType::validate($entityType);

        $response = $this->client->post("api/v4/$entityType/tags", [$data]);

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

        return $this->client->patch(
            "api/v4/$entityType/$entityId",
            ['_embedded' => ['tags' => null]],
        );
    }

    private function mutateEntityTags(
        string $entityType,
        int $entityId,
        string $operation,
        array $tags
    ): array {
        $entityType = EntityType::validate($entityType);
        $payload = [];

        foreach ($tags as $tag) {
            $payload[] = self::tagPayload($tag);
        }

        return $this->client->patch("api/v4/$entityType/$entityId", [$operation => $payload]);
    }

    /**
     * Привести тег к формату amoCRM.
     *
     * Целое число — это ID существующего тега. Строка — название: amoCRM
     * найдёт тег с таким названием или заведёт новый. Поэтому числовая
     * строка '15' — это название «15», а не ID. Массив уходит как есть: так
     * передают тег целиком, например вместе с цветом.
     *
     * @param mixed $tag
     */
    private static function tagPayload($tag): array
    {
        if (is_array($tag)) {
            return $tag;
        }

        if (is_int($tag)) {
            return ['id' => $tag];
        }

        return ['name' => (string) $tag];
    }
}
