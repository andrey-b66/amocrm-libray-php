<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

final class LinkLiveTest extends LiveTestCase
{
    /**
     * Сделка, контакт и компания прогона связываются друг с другом, читаются
     * со всех сторон и в конце отвязываются, как бы ни прошли проверки.
     */
    public function testLinksBetweenLeadContactAndCompany(): void
    {
        $links = $this->amocrm()->links();
        $leadId = $this->leadId();
        $contactId = $this->contactId();
        $companyId = $this->companyId();

        self::assertSame([], $links->findLinks('leads', $leadId), 'У новой сделки нет связей.');
        self::assertNull($links->findMainContactForLead($leadId));

        try {
            $links->linkContactToLead($contactId, $leadId, true);
            $links->linkCompanyToLead($companyId, $leadId);
            $links->linkContactToCompany($contactId, $companyId);

            $leadLinks = $links->findLinks('leads', $leadId);
            self::assertContains("contacts:$contactId", self::pairs($leadLinks));
            self::assertContains("companies:$companyId", self::pairs($leadLinks));

            self::assertSame(
                $contactId,
                $links->findMainContactForLead($leadId)['id'] ?? null,
                'Главный контакт читается из metadata.main_contact.',
            );

            // Связь видна с любой стороны.
            self::assertContains("leads:$leadId", self::pairs($links->findLinks('contacts', $contactId)));
            self::assertContains("contacts:$contactId", self::pairs($links->findLinks('companies', $companyId)));

            // Сущности по ID связей грузит их собственный репозиторий.
            $contacts = $this->amocrm()->contacts()->findByIds(self::idsOfType($leadLinks, 'contacts'));
            self::assertSame([$contactId], array_column($contacts, 'id'));

            self::assertContains(
                $leadId,
                array_column($links->findActiveLeads('contacts', $contactId), 'id'),
                'Сделка на открытом этапе считается активной.',
            );
            self::assertContains($leadId, array_column($links->findActiveLeads('companies', $companyId), 'id'));
        } finally {
            $links->unlinkContactFromLead($contactId, $leadId);
            $links->unlinkCompanyFromLead($companyId, $leadId);
            $links->unlinkContactFromCompany($contactId, $companyId);
        }

        self::assertSame([], $links->findLinks('leads', $leadId), 'Связи сделки сняты.');
        self::assertSame([], $links->findLinks('contacts', $contactId), 'Связи контакта сняты.');
    }

    /** Связи строками `тип:ID` — так их удобно сравнивать. */
    private static function pairs(array $links): array
    {
        $pairs = [];

        foreach ($links as $link) {
            $pairs[] = $link['to_entity_type'] . ':' . $link['to_entity_id'];
        }

        return $pairs;
    }

    private static function idsOfType(array $links, string $entityType): array
    {
        $ids = [];

        foreach ($links as $link) {
            if ($link['to_entity_type'] === $entityType) {
                $ids[] = $link['to_entity_id'];
            }
        }

        return $ids;
    }
}
