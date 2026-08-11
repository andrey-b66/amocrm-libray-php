<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\EntityType;

/**
 * Репозиторий связей между контактами, сделками и компаниями.
 *
 * Связь двусторонняя: после привязки контакта к сделке она видна и у контакта,
 * и у сделки. Направление запроса определяет только endpoint API. Типы сущностей
 * — `contacts`, `leads` или `companies`.
 *
 * Пример: linkContactToLead($contactId, $leadId, true) — третий аргумент делает
 * контакт главным для сделки.
 */
final class Link
{
    private ApiClient $request;

    public function __construct(ApiClient $apiClient)
    {
        $this->request = $apiClient;
    }

    /**
     * Связать две сущности произвольного направления.
     *
     * `$metadata` — дополнительные данные связи, например `['is_main' => true]`.
     *
     * Пример: link('leads', $leadId, 'contacts', $contactId, ['is_main' => true])
     */
    public function link(
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
        array $metadata = [],
    ): array {
        $sourceType = EntityType::validate($sourceType);

        $response = $this->request->post(
            "api/v4/$sourceType/$sourceId/link",
            [$this->buildPayload($targetType, $targetId, $metadata)],
        );

        return $response['_embedded']['links'][0] ?? [];
    }

    /** Отвязать две сущности. */
    public function unlink(
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
        array $metadata = [],
    ): bool {
        $sourceType = EntityType::validate($sourceType);

        $this->request->post(
            "api/v4/$sourceType/$sourceId/unlink",
            [$this->buildPayload($targetType, $targetId, $metadata)],
        );

        return true;
    }

    /**
     * Получить связи сущности — пары ID, без данных самих сущностей.
     *
     * Пример: findForEntity('leads', $leadId) — все связи сделки
     * Пример: findForEntity('leads', $leadId, 'contacts') — только контакты
     */
    public function findForEntity(
        string $entityType,
        int $entityId,
        ?string $targetType = null,
        ?int $targetId = null,
    ): array {
        $entityType = EntityType::validate($entityType);
        $query = '';

        if ($targetType !== null) {
            $query = 'filter[to_entity_type]=' . EntityType::validate($targetType);
        }

        if ($targetId !== null) {
            $query .= "&filter[to_entity_id]=$targetId";
        }

        $response = $this->request->get("api/v4/$entityType/$entityId/links", $query);

        return array_values($response['_embedded']['links'] ?? []);
    }

    /**
     * Получить связанные сущности целиком, а не только пары ID.
     *
     * Пример: findRelatedEntities('leads', $leadId, 'contacts', 'companies')
     */
    public function findRelatedEntities(
        string $sourceType,
        int $sourceId,
        string $targetType,
        string $with = '',
    ): array {
        $targetType = EntityType::validate($targetType);
        $targetIds = [];

        foreach ($this->findForEntity($sourceType, $sourceId, $targetType) as $link) {
            $targetId = $link['to_entity_id'] ?? null;

            if (is_int($targetId) && ($link['to_entity_type'] ?? null) === $targetType) {
                $targetIds[$targetId] = $targetId;
            }
        }

        return $this->repository($targetType)->findByIds(array_values($targetIds), $with);
    }

    public function findLeadsForContact(int $contactId, string $with = ''): array
    {
        return $this->findRelatedEntities(EntityType::CONTACT, $contactId, EntityType::LEAD, $with);
    }

    public function findCompanyForContact(int $contactId, string $with = ''): ?array
    {
        return $this->findRelatedEntities(EntityType::CONTACT, $contactId, EntityType::COMPANY, $with)[0] ?? null;
    }

    public function findContactsForCompany(int $companyId, string $with = ''): array
    {
        return $this->findRelatedEntities(EntityType::COMPANY, $companyId, EntityType::CONTACT, $with);
    }

    public function findLeadsForCompany(int $companyId, string $with = ''): array
    {
        return $this->findRelatedEntities(EntityType::COMPANY, $companyId, EntityType::LEAD, $with);
    }

    public function findContactsForLead(int $leadId, string $with = ''): array
    {
        return $this->findRelatedEntities(EntityType::LEAD, $leadId, EntityType::CONTACT, $with);
    }

    public function findFirstContactForLead(int $leadId, string $with = ''): ?array
    {
        return $this->findContactsForLead($leadId, $with)[0] ?? null;
    }

    /** Получить главный контакт сделки по метаданным связи `is_main`. */
    public function findMainContactForLead(int $leadId, string $with = ''): ?array
    {
        foreach ($this->findForEntity(EntityType::LEAD, $leadId, EntityType::CONTACT) as $link) {
            $contactId = $link['to_entity_id'] ?? null;

            if (($link['metadata']['is_main'] ?? false) === true && is_int($contactId)) {
                return $this->repository(EntityType::CONTACT)->findById($contactId, $with);
            }
        }

        return null;
    }

    public function findCompanyForLead(int $leadId, string $with = ''): ?array
    {
        return $this->findRelatedEntities(EntityType::LEAD, $leadId, EntityType::COMPANY, $with)[0] ?? null;
    }

    /** Связать контакт со сделкой. */
    public function linkContactToLead(int $contactId, int $leadId, ?bool $isMain = null): array
    {
        $metadata = $isMain === null ? [] : ['is_main' => $isMain];

        return $this->link(EntityType::LEAD, $leadId, EntityType::CONTACT, $contactId, $metadata);
    }

    /** Связать компанию со сделкой. */
    public function linkCompanyToLead(int $companyId, int $leadId): array
    {
        return $this->link(EntityType::LEAD, $leadId, EntityType::COMPANY, $companyId);
    }

    /** Связать контакт с компанией. */
    public function linkContactToCompany(int $contactId, int $companyId): array
    {
        return $this->link(EntityType::COMPANY, $companyId, EntityType::CONTACT, $contactId);
    }

    public function unlinkContactFromLead(int $contactId, int $leadId): bool
    {
        return $this->unlink(EntityType::LEAD, $leadId, EntityType::CONTACT, $contactId);
    }

    public function unlinkCompanyFromLead(int $companyId, int $leadId): bool
    {
        return $this->unlink(EntityType::LEAD, $leadId, EntityType::COMPANY, $companyId);
    }

    public function unlinkContactFromCompany(int $contactId, int $companyId): bool
    {
        return $this->unlink(EntityType::COMPANY, $companyId, EntityType::CONTACT, $contactId);
    }

    private function buildPayload(string $targetType, int $targetId, array $metadata): array
    {
        $payload = [
            'to_entity_id' => $targetId,
            'to_entity_type' => EntityType::validate($targetType),
        ];

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        return $payload;
    }

    /** Сущности грузит их собственный репозиторий — он уже умеет и пачки, и `with`. */
    private function repository(string $entityType): AbstractApiRepository
    {
        return match (EntityType::validate($entityType)) {
            EntityType::CONTACT => new Contact($this->request),
            EntityType::LEAD => new Lead($this->request),
            EntityType::COMPANY => new Company($this->request),
        };
    }
}
