<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\Link;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

final class LinkTest extends FakeAmocrmTestCase
{
    /** @dataProvider linkCalls */
    public function testLink(callable $action, string $path, array $expectedBody): void
    {
        $link = ['to_entity_id' => 5, 'to_entity_type' => 'contacts'];
        $this->respond(['_embedded' => ['links' => [$link]]]);

        self::assertSame($link, $action($this->links()));
        self::assertSame(["POST $path"], $this->requestLog());
        self::assertSame([$expectedBody], $this->lastRequest()['body']);
    }

    public function linkCalls(): array
    {
        return [
            'главный контакт к сделке' => [
                fn (Link $links) => $links->linkContactToLead(5, 10, true),
                '/api/v4/leads/10/link',
                ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['is_main' => true]],
            ],
            'неглавный контакт к сделке' => [
                fn (Link $links) => $links->linkContactToLead(5, 10, false),
                '/api/v4/leads/10/link',
                ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['is_main' => false]],
            ],
            'контакт к сделке без признака' => [
                fn (Link $links) => $links->linkContactToLead(5, 10),
                '/api/v4/leads/10/link',
                ['to_entity_id' => 5, 'to_entity_type' => 'contacts'],
            ],
            'компания к сделке' => [
                fn (Link $links) => $links->linkCompanyToLead(7, 10),
                '/api/v4/leads/10/link',
                ['to_entity_id' => 7, 'to_entity_type' => 'companies'],
            ],
            'контакт к компании' => [
                fn (Link $links) => $links->linkContactToCompany(5, 7),
                '/api/v4/companies/7/link',
                ['to_entity_id' => 5, 'to_entity_type' => 'contacts'],
            ],
        ];
    }

    /** @dataProvider unlinkCalls */
    public function testUnlink(callable $action, string $path, array $expectedBody): void
    {
        $this->respondNoContent();

        $action($this->links());

        self::assertSame(["POST $path"], $this->requestLog());
        self::assertSame([$expectedBody], $this->lastRequest()['body']);
    }

    public function unlinkCalls(): array
    {
        return [
            'контакт от сделки' => [
                fn (Link $links) => $links->unlinkContactFromLead(5, 10),
                '/api/v4/leads/10/unlink',
                ['to_entity_id' => 5, 'to_entity_type' => 'contacts'],
            ],
            'компания от сделки' => [
                fn (Link $links) => $links->unlinkCompanyFromLead(7, 10),
                '/api/v4/leads/10/unlink',
                ['to_entity_id' => 7, 'to_entity_type' => 'companies'],
            ],
            'контакт от компании' => [
                fn (Link $links) => $links->unlinkContactFromCompany(5, 7),
                '/api/v4/companies/7/unlink',
                ['to_entity_id' => 5, 'to_entity_type' => 'contacts'],
            ],
        ];
    }

    /**
     * Фильтры в запрос не кладутся: по одному типу amoCRM их игнорирует и
     * всё равно отдаёт все связи — так она отвечает и на живом аккаунте.
     */
    public function testFindLinksReadsAllLinksWithoutFilters(): void
    {
        $links = [
            ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => true]],
            ['to_entity_id' => 7, 'to_entity_type' => 'companies'],
        ];
        $this->respond(['_embedded' => ['links' => $links]]);

        self::assertSame($links, $this->links()->findLinks('leads', 10));
        self::assertSame(['GET /api/v4/leads/10/links'], $this->requestLog());
    }

    public function testFindLinksReturnsEmptyListOnNoContent(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->links()->findLinks('contacts', 5));
    }

    public function testFindLinksRejectsUnsupportedEntityType(): void
    {
        $exception = self::exceptionFrom(fn () => $this->links()->findLinks('customers', 5));

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame([], $this->requests());
    }

    public function testFindActiveLeadsSkipsClosedLeadsAndOtherLinkTypes(): void
    {
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 1, 'to_entity_type' => 'leads'],
            ['to_entity_id' => 7, 'to_entity_type' => 'companies'],
            ['to_entity_id' => 2, 'to_entity_type' => 'leads'],
            ['to_entity_id' => 3, 'to_entity_type' => 'leads'],
        ]]]);
        $this->respond(self::page('leads', [
            ['id' => 1, 'status_id' => 142],
            ['id' => 2, 'status_id' => 555],
            ['id' => 3, 'status_id' => 143],
        ]));

        self::assertSame([['id' => 2, 'status_id' => 555]], $this->links()->findActiveLeads('contacts', 5));
        self::assertSame([
            'GET /api/v4/contacts/5/links',
            'GET /api/v4/leads?filter[id][0]=1&filter[id][1]=2&filter[id][2]=3&page=1&limit=3',
        ], $this->requestLog());
    }

    public function testFindActiveLeadsWithOwnClosedStatuses(): void
    {
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 1, 'to_entity_type' => 'leads'],
            ['to_entity_id' => 2, 'to_entity_type' => 'leads'],
        ]]]);
        $this->respond(self::page('leads', [['id' => 1, 'status_id' => 142], ['id' => 2, 'status_id' => 555]]));

        self::assertSame(
            [['id' => 1, 'status_id' => 142]],
            $this->links()->findActiveLeads('contacts', 5, [555]),
        );
    }

    public function testFindActiveLeadsForCompanyPassesWith(): void
    {
        $this->respond(['_embedded' => ['links' => [['to_entity_id' => 1, 'to_entity_type' => 'leads']]]]);
        $this->respond(self::page('leads', [['id' => 1, 'status_id' => 555]]));

        $leads = $this->links()->findActiveLeads('companies', 7, [142, 143], 'contacts');

        self::assertSame([['id' => 1, 'status_id' => 555]], $leads);
        self::assertSame([
            'GET /api/v4/companies/7/links',
            'GET /api/v4/leads?filter[id][0]=1&page=1&limit=1&with=contacts',
        ], $this->requestLog());
    }

    public function testFindActiveLeadsWithoutLeadLinksSendsOneRequest(): void
    {
        $this->respond(['_embedded' => ['links' => [['to_entity_id' => 7, 'to_entity_type' => 'companies']]]]);

        self::assertSame([], $this->links()->findActiveLeads('contacts', 5));
        self::assertCount(1, $this->requests());
    }

    public function testFindMainContactForLead(): void
    {
        // Так отвечает живая amoCRM: в прочитанных связях признак зовётся main_contact.
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => false]],
            ['to_entity_id' => 6, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => true]],
        ]]]);
        $this->respond(['id' => 6, 'name' => 'Иван']);

        self::assertSame(['id' => 6, 'name' => 'Иван'], $this->links()->findMainContactForLead(10, 'leads'));
        self::assertSame([
            'GET /api/v4/leads/10/links',
            'GET /api/v4/contacts/6?with=leads',
        ], $this->requestLog());
    }

    public function testFindMainContactForLeadReturnsNullWithoutMainContact(): void
    {
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => false]],
            ['to_entity_id' => 6, 'to_entity_type' => 'contacts'],
        ]]]);

        self::assertNull($this->links()->findMainContactForLead(10));
        self::assertCount(1, $this->requests());
    }

    public function testFindMainContactForLeadIgnoresOtherLinkTypes(): void
    {
        // Признак main_contact у связи с компанией контактом её не делает.
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 7, 'to_entity_type' => 'companies', 'metadata' => ['main_contact' => true]],
        ]]]);

        self::assertNull($this->links()->findMainContactForLead(10));
        self::assertCount(1, $this->requests());
    }

    private function links(): Link
    {
        return $this->amocrm()->links();
    }
}
