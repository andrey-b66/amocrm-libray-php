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
     * Создать задачу, привязанную к сущности.
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

        // Задача здесь всегда одна, поэтому из списка создаётся и возвращается
        // одна: create() работает списками, как и сама amoCRM.
        return $this->create([$data])[0] ?? [];
    }

    /**
     * Получить задачи конкретной сущности.
     *
     * Пример: findForEntity('leads', $leadId, 'filter[is_completed]=0')
     * Пример: findForEntity('leads', $leadId, '', 250, null) — все задачи сущности
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
