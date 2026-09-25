<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;
use Amocrm\Support\ApiWriter;
use Amocrm\Support\EntityType;

/**
 * Репозиторий справочников тегов и тегов конкретных сущностей.
 *
 * Первым аргументом методы принимают `leads`, `contacts` или `companies`.
 */
final class Tag
{
    private ApiClient $client;

    /** Обычно репозиторий берут у фасада: $amocrm->tags(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Получить теги из справочника: по умолчанию первую страницу (до 250), с $pages = null — все.
     *
     * Пример: find('leads', 'query=Важная заявка')
     *
     * @param int|null $pages сколько страниц прочитать; null — все
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
     * Создать теги в справочнике: принимает список и возвращает список. На тег с
     * существующим названием amoCRM возвращает его, а не создаёт новый.
     *
     * Пример: create('leads', [['name' => 'Важная заявка'], ['name' => 'Повторный клиент']])
     */
    public function create(string $entityType, array $tags): array
    {
        $entityType = EntityType::validate($entityType);

        return ApiWriter::write($this->client, 'post', "api/v4/$entityType/tags", 'tags', $tags);
    }

    /**
     * Добавить теги к сущности, не затрагивая уже назначенные. Тег — ID, название
     * или массив формата amoCRM.
     *
     * Пример: addToEntity('leads', $leadId, [$tagId, 'Повторный клиент'])
     */
    public function addToEntity(string $entityType, int $entityId, array $tags): array
    {
        return $this->mutateEntityTags($entityType, $entityId, 'tags_to_add', $tags);
    }

    /**
     * Снять с сущности отдельные теги, не затрагивая остальные.
     *
     * Пример: removeFromEntity('leads', $leadId, [$tagId])
     */
    public function removeFromEntity(string $entityType, int $entityId, array $tags): array
    {
        return $this->mutateEntityTags($entityType, $entityId, 'tags_to_delete', $tags);
    }

    /** Снять с сущности все теги. */
    public function clearForEntity(string $entityType, int $entityId): array
    {
        $entityType = EntityType::validate($entityType);

        return $this->client->patch(
            "api/v4/$entityType/$entityId",
            ['_embedded' => ['tags' => null]],
        );
    }

    /** Добавить или снять теги сущности: $operation — `tags_to_add` или `tags_to_delete`. */
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
     * Привести тег к формату amoCRM: целое число — ID, строка — название (и '15'
     * тоже), массив уходит как есть.
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
