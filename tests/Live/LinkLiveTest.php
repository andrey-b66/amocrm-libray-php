<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/** Связи между сделкой, контактом и компанией на живом аккаунте. */
final class LinkLiveTest extends LiveTestCase
{
    /**
     * Сделка, контакт и компания прогона связываются попарно, связи читаются с обеих
     * сторон, а в конце снимаются, как бы ни прошли проверки.
     */
    public function testLinkAndUnlinkLeadContactAndCompany(): void
    {
        $links = $this->amocrm()->links();
        $leadId = $this->leadId();
        $contactId = $this->contactId();
        $companyId = $this->companyId();

        self::assertSame([], $links->findLinkedIds('leads', $leadId, 'contacts'), 'У новой сделки нет контактов.');
        self::assertSame([], $links->findLinkedIds('leads', $leadId, 'companies'), 'У новой сделки нет компаний.');
        self::assertNull($links->findMainContactId($leadId));

        try {
            $contactLink = $links->linkContactToLead($contactId, $leadId, true);
            $companyLink = $links->linkCompanyToLead($companyId, $leadId);
            $contactCompanyLink = $links->linkContactToCompany($contactId, $companyId);

            // Методы привязки возвращают созданную связь.
            self::assertSame([$contactId, 'contacts'], [$contactLink['to_entity_id'] ?? null, $contactLink['to_entity_type'] ?? null]);
            self::assertSame([$companyId, 'companies'], [$companyLink['to_entity_id'] ?? null, $companyLink['to_entity_type'] ?? null]);
            self::assertSame(
                [$contactId, 'contacts'],
                [$contactCompanyLink['to_entity_id'] ?? null, $contactCompanyLink['to_entity_type'] ?? null],
            );

            // Каждая связь видна с обеих сторон.
            self::assertSame([$contactId], $links->findLinkedIds('leads', $leadId, 'contacts'));
            self::assertContains($leadId, $links->findLinkedIds('contacts', $contactId, 'leads'));
            self::assertSame([$companyId], $links->findLinkedIds('leads', $leadId, 'companies'));
            self::assertContains($leadId, $links->findLinkedIds('companies', $companyId, 'leads'));
            self::assertSame([$companyId], $links->findLinkedIds('contacts', $contactId, 'companies'));
            self::assertSame([$contactId], $links->findLinkedIds('companies', $companyId, 'contacts'));

            self::assertSame($contactId, $links->findMainContactId($leadId), 'Контакт привязан главным.');

            // Сущности по ID связей загружают их репозитории.
            $contacts = $this->amocrm()->contacts()->findByIds($links->findLinkedIds('leads', $leadId, 'contacts'));
            self::assertSame([$contactId], array_column($contacts, 'id'));

            $activeLeads = $this->amocrm()->leads()->findActiveByIds($links->findLinkedIds('contacts', $contactId, 'leads'));
            self::assertContains($leadId, array_column($activeLeads, 'id'), 'Сделка на открытом этапе считается активной.');
        } finally {
            $links->unlinkContactFromLead($contactId, $leadId);
            $links->unlinkCompanyFromLead($companyId, $leadId);
            $links->unlinkContactFromCompany($contactId, $companyId);
        }

        // После отвязки связей нет ни с одной стороны.
        self::assertSame([], $links->findLinkedIds('leads', $leadId, 'contacts'), 'Контакт отвязан от сделки.');
        self::assertNotContains($leadId, $links->findLinkedIds('contacts', $contactId, 'leads'));
        self::assertSame([], $links->findLinkedIds('leads', $leadId, 'companies'), 'Компания отвязана от сделки.');
        self::assertNotContains($leadId, $links->findLinkedIds('companies', $companyId, 'leads'));
        self::assertSame([], $links->findLinkedIds('contacts', $contactId, 'companies'), 'Контакт отвязан от компании.');
        self::assertSame([], $links->findLinkedIds('companies', $companyId, 'contacts'));
        self::assertNull($links->findMainContactId($leadId), 'Главного контакта больше нет.');
    }
}
