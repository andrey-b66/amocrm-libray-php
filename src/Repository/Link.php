<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\EntityType;

/**
 * Связи между контактами, сделками и компаниями. Связь видна с обеих сторон;
 * чтение отдаёт ID, а сами сущности загружают их репозитории.
 *
 * Пример: $contactIds = $amocrm->links()->findLinkedIds('leads', $leadId, 'contacts');
 *         $contacts = $amocrm->contacts()->findByIds($contactIds);
 */
final class Link
{
    private ApiClient $client;

    /** Обычно репозиторий берут у фасада: $amocrm->links(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /** Привязать контакт к сделке; $isMain — сделать главным, null — решает amoCRM. */
    public function linkContactToLead(int $contactId, int $leadId, ?bool $isMain = null): array
    {
        $metadata = $isMain === null ? [] : ['is_main' => $isMain];

        return $this->link(EntityType::LEAD, $leadId, EntityType::CONTACT, $contactId, $metadata);
    }

    /** Привязать компанию к сделке. */
    public function linkCompanyToLead(int $companyId, int $leadId): array
    {
        return $this->link(EntityType::LEAD, $leadId, EntityType::COMPANY, $companyId);
    }

    /** Привязать контакт к компании. */
    public function linkContactToCompany(int $contactId, int $companyId): array
    {
        return $this->link(EntityType::COMPANY, $companyId, EntityType::CONTACT, $contactId);
    }

    /** Отвязать контакт от сделки. */
    public function unlinkContactFromLead(int $contactId, int $leadId): void
    {
        $this->unlink(EntityType::LEAD, $leadId, EntityType::CONTACT, $contactId);
    }

    /** Отвязать компанию от сделки. */
    public function unlinkCompanyFromLead(int $companyId, int $leadId): void
    {
        $this->unlink(EntityType::LEAD, $leadId, EntityType::COMPANY, $companyId);
    }

    /** Отвязать контакт от компании. */
    public function unlinkContactFromCompany(int $contactId, int $companyId): void
    {
        $this->unlink(EntityType::COMPANY, $companyId, EntityType::CONTACT, $contactId);
    }

    /**
     * Получить ID связанных сущностей типа $targetType.
     *
     * Пример: findLinkedIds('contacts', $contactId, 'leads')
     */
    public function findLinkedIds(string $entityType, int $entityId, string $targetType): array
    {
        $targetType = EntityType::validate($targetType);
        $ids = [];

        foreach ($this->findLinks($entityType, $entityId) as $link) {
            $targetId = $link['to_entity_id'] ?? null;

            if (($link['to_entity_type'] ?? null) === $targetType && is_int($targetId)) {
                $ids[] = $targetId;
            }
        }

        return $ids;
    }

    /**
     * Получить ID главного контакта сделки; null, если его нет.
     *
     * Пример: findMainContactId($leadId)
     */
    public function findMainContactId(int $leadId): ?int
    {
        foreach ($this->findLinks(EntityType::LEAD, $leadId) as $link) {
            $contactId = $link['to_entity_id'] ?? null;

            if (($link['to_entity_type'] ?? null) !== EntityType::CONTACT) {
                continue;
            }

            // При чтении признак главного контакта называется `main_contact`, а не `is_main`.
            if (($link['metadata']['main_contact'] ?? false) === true && is_int($contactId)) {
                return $contactId;
            }
        }

        return null;
    }

    /** Все связи сущности. Фильтров и страниц у этого метода amoCRM нет — приходят все сразу. */
    private function findLinks(string $entityType, int $entityId): array
    {
        $entityType = EntityType::validate($entityType);
        $response = $this->client->get("api/v4/$entityType/$entityId/links");

        return $response['_embedded']['links'] ?? [];
    }

    /** Создать связь и вернуть её из ответа amoCRM. */
    private function link(
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId,
        array $metadata = []
    ): array {
        $payload = ['to_entity_id' => $targetId, 'to_entity_type' => $targetType];

        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $response = $this->client->post("api/v4/$sourceType/$sourceId/link", [$payload]);

        return $response['_embedded']['links'][0] ?? [];
    }

    /** Снять связь. */
    private function unlink(string $sourceType, int $sourceId, string $targetType, int $targetId): void
    {
        $this->client->post(
            "api/v4/$sourceType/$sourceId/unlink",
            [['to_entity_id' => $targetId, 'to_entity_type' => $targetType]],
        );
    }
}
