<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Support\ApiReader;
use Amocrm\Support\EntityType;

/**
 * Репозиторий задач контактов, сделок и компаний.
 *
 * Аргумент `$entityType` — `leads`, `contacts` или `companies`.
 */
final class Task extends AbstractApiRepository
{
    protected function endpoint(): string
    {
        return 'api/v4/tasks';
    }

    protected function embeddedKey(): string
    {
        return 'tasks';
    }

    /**
     * Создать одну задачу, привязанную к сущности, и вернуть её.
     *
     * Пример: createForEntity('leads', $leadId, ['text' => 'Перезвонить клиенту', 'complete_till' => time() + 3600])
     */
    public function createForEntity(string $entityType, int $entityId, array $data): array
    {
        $data['entity_type'] = EntityType::validate($entityType);
        $data['entity_id'] = $entityId;

        return $this->create([$data])[0] ?? [];
    }

    /**
     * Получить задачи сущности: по умолчанию первую страницу (до 250), с $pages = null — все.
     *
     * Пример: findForEntity('leads', $leadId, 'filter[is_completed]=0', 250, null)
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

        return $this->find(
            $query . "&filter[entity_type]=$entityType&filter[entity_id]=$entityId",
            $limit,
            $pages,
        );
    }
}
