<?php

declare(strict_types=1);

namespace Amocrm\Repository;

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
     * Создать задачу, привязанную к сущности, по данным формата amoCRM API v4.
     *
     * Пример: createForEntity('leads', $leadId, [
     *     'text' => 'Перезвонить клиенту',
     *     'complete_till' => time() + 3600,
     * ])
     */
    public function createForEntity(string $entityType, int $entityId, array $data): array
    {
        $data['entity_type'] = EntityType::validate($entityType);
        $data['entity_id'] = $entityId;

        return $this->create($data);
    }

    /**
     * Получить задачи конкретной сущности.
     *
     * Пример: findForEntity('leads', $leadId, 'filter[is_completed]=0')
     */
    public function findForEntity(
        string $entityType,
        int $entityId,
        string $query = '',
        int $page = 1,
        int $limit = self::MAX_PAGE_SIZE,
    ): array {
        $entityType = EntityType::validate($entityType);

        return $this->find(
            $query . "&filter[entity_type]=$entityType&filter[entity_id]=$entityId",
            $page,
            $limit,
        );
    }
}
