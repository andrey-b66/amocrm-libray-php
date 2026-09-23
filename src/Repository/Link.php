<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\EntityType;

/**
 * Репозиторий связей между контактами, сделками и компаниями.
 *
 * Связь двусторонняя: после привязки контакта к сделке она видна и у контакта,
 * и у сделки, поэтому читать её можно с любой стороны.
 *
 * Чтение отдаёт связи — пары ID и `metadata`. Сами сущности грузят их
 * репозитории: так видно, сколько запросов уходит, и можно взять только нужные.
 *
 * Пример: $links = $amocrm->links()->findLinks('leads', $leadId);
 *         $contacts = $amocrm->contacts()->findByIds([5, 6]);
 */
final class Link
{
    private ApiClient $client;

    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Связать контакт со сделкой.
     *
     * Третий аргумент делает контакт главным у сделки. По умолчанию признак не
     * передаётся вовсе, и amoCRM решает сама: первый контакт сделки становится
     * главным.
     */
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

    public function unlinkContactFromLead(int $contactId, int $leadId): void
    {
        $this->unlink(EntityType::LEAD, $leadId, EntityType::CONTACT, $contactId);
    }

    public function unlinkCompanyFromLead(int $companyId, int $leadId): void
    {
        $this->unlink(EntityType::LEAD, $leadId, EntityType::COMPANY, $companyId);
    }

    public function unlinkContactFromCompany(int $contactId, int $companyId): void
    {
        $this->unlink(EntityType::COMPANY, $companyId, EntityType::CONTACT, $contactId);
    }

    /**
     * Получить все связи сущности — пары ID и `metadata`.
     *
     * Нужные отбирают по `to_entity_type`, а сущности грузят их репозиторием:
     *
     *     $contactIds = [];
     *     foreach ($amocrm->links()->findLinks('leads', $leadId) as $link) {
     *         if ($link['to_entity_type'] === 'contacts') {
     *             $contactIds[] = $link['to_entity_id'];
     *         }
     *     }
     *     $contacts = $amocrm->contacts()->findByIds($contactIds);
     */
    public function findLinks(string $entityType, int $entityId): array
    {
        $entityType = EntityType::validate($entityType);

        // Фильтры в запрос не кладутся: по одному типу или одному ID amoCRM их
        // молча пропускает и всё равно отдаёт все связи. Страниц у связей тоже
        // нет — `page` и `limit` она пропускает так же.
        $response = $this->client->get("api/v4/$entityType/$entityId/links");

        return $response['_embedded']['links'] ?? [];
    }

    /**
     * Получить сделки контакта или компании, кроме закрытых.
     *
     * Фильтровать это на стороне amoCRM нельзя: она отбирает только по
     * перечисленным статусам, а не по всем, кроме перечисленных. Поэтому
     * закрытые отсеиваются здесь, уже после загрузки сделок.
     *
     * Пример: findActiveLeads('contacts', $contactId)
     */
    public function findActiveLeads(
        string $entityType,
        int $entityId,
        array $excludedStatusIds = Lead::DEFAULT_CLOSED_STATUS_IDS,
        string $with = ''
    ): array {
        $leadIds = [];

        foreach ($this->findLinks($entityType, $entityId) as $link) {
            $leadId = $link['to_entity_id'] ?? null;

            if (($link['to_entity_type'] ?? null) === EntityType::LEAD && is_int($leadId)) {
                $leadIds[] = $leadId;
            }
        }

        $active = [];

        foreach ((new Lead($this->client))->findByIds($leadIds, $with) as $lead) {
            if (!in_array($lead['status_id'] ?? null, $excludedStatusIds, true)) {
                $active[] = $lead;
            }
        }

        return $active;
    }

    /**
     * Получить главный контакт сделки.
     *
     * При привязке главный контакт помечается `is_main`, а в прочитанных
     * связях amoCRM называет тот же признак иначе — `main_contact`.
     */
    public function findMainContactForLead(int $leadId, string $with = ''): ?array
    {
        foreach ($this->findLinks(EntityType::LEAD, $leadId) as $link) {
            $contactId = $link['to_entity_id'] ?? null;

            if (($link['to_entity_type'] ?? null) !== EntityType::CONTACT) {
                continue;
            }

            if (($link['metadata']['main_contact'] ?? false) === true && is_int($contactId)) {
                return (new Contact($this->client))->findById($contactId, $with);
            }
        }

        return null;
    }

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

    private function unlink(string $sourceType, int $sourceId, string $targetType, int $targetId): void
    {
        $this->client->post(
            "api/v4/$sourceType/$sourceId/unlink",
            [['to_entity_id' => $targetId, 'to_entity_type' => $targetType]],
        );
    }
}
