<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Exception\ApiException;
use Amocrm\Support\EntityType;

/** Репозиторий сделок amoCRM (сущность lead в API). */
final class Lead extends AbstractApiRepository
{
    public const DEFAULT_CLOSED_STATUS_IDS = [142, 143];

    protected function endpoint(): string
    {
        return 'api/v4/leads';
    }

    protected function embeddedKey(): string
    {
        return 'leads';
    }

    /** Получить все активные сделки контакта. */
    public function findActiveByContactId(
        int $contactId,
        array $excludedStatusIds = self::DEFAULT_CLOSED_STATUS_IDS,
    ): array {
        return $this->findActiveByRelatedEntity(EntityType::CONTACT, $contactId, $excludedStatusIds);
    }

    /** Найти все активные сделки, связанные с компанией. */
    public function findActiveByCompanyId(
        int $companyId,
        array $excludedStatusIds = self::DEFAULT_CLOSED_STATUS_IDS,
    ): array {
        return $this->findActiveByRelatedEntity(EntityType::COMPANY, $companyId, $excludedStatusIds);
    }

    private function findActiveByRelatedEntity(
        string $entityType,
        int $entityId,
        array $excludedStatusIds,
    ): array {
        $entityType = EntityType::validate($entityType);

        try {
            $entity = $this->request->get("api/v4/$entityType/$entityId", 'with=leads');
        } catch (ApiException $exception) {
            if ($exception->getCode() === 404) {
                return [];
            }

            throw $exception;
        }

        $leadIds = [];

        foreach ($entity['_embedded']['leads'] ?? [] as $linkedLead) {
            $leadId = $linkedLead['id'] ?? null;

            if (is_int($leadId)) {
                $leadIds[$leadId] = $leadId;
            }
        }

        // amoCRM умеет фильтровать только по включённым статусам, поэтому
        // закрытые сделки отсеиваем уже здесь.
        return array_values(array_filter(
            $this->findByIds(array_values($leadIds)),
            static fn (array $lead): bool => !in_array($lead['status_id'] ?? null, $excludedStatusIds, true),
        ));
    }
}
