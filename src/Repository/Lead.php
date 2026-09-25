<?php

declare(strict_types=1);

namespace Amocrm\Repository;

/** Репозиторий сделок amoCRM. */
final class Lead extends AbstractSearchableRepository
{
    /** Системные статусы закрытых сделок: 142 — успешно, 143 — не реализовано. */
    public const DEFAULT_CLOSED_STATUS_IDS = [142, 143];

    /**
     * Получить сделки по списку ID, кроме сделок со статусами из $excludedStatusIds.
     *
     * Пример: findActiveByIds([10, 20, 30])
     */
    public function findActiveByIds(
        array $leadIds,
        string $with = '',
        array $excludedStatusIds = self::DEFAULT_CLOSED_STATUS_IDS
    ): array {
        $active = [];

        foreach ($this->findByIds($leadIds, $with) as $lead) {
            if (!in_array($lead['status_id'] ?? null, $excludedStatusIds, true)) {
                $active[] = $lead;
            }
        }

        return $active;
    }

    protected function endpoint(): string
    {
        return 'api/v4/leads';
    }

    protected function embeddedKey(): string
    {
        return 'leads';
    }
}
