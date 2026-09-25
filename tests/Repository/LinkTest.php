<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\Link;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

/** Привязка, отвязка и чтение связей между сущностями. */
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

    /** Фильтров в запросе нет: amoCRM их пропускает и отдаёт все связи, как на живом аккаунте. */
    public function testFindLinkedIdsKeepsOnlyRequestedTypeInLinkOrder(): void
    {
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 6, 'to_entity_type' => 'contacts'],
            ['to_entity_id' => 7, 'to_entity_type' => 'companies'],
            ['to_entity_id' => 5, 'to_entity_type' => 'contacts'],
        ]]]);

        self::assertSame([6, 5], $this->links()->findLinkedIds('leads', 10, 'contacts'));
        self::assertSame(['GET /api/v4/leads/10/links'], $this->requestLog());
    }

    public function testFindLinkedIdsWithoutSuchLinks(): void
    {
        $this->respond(['_embedded' => ['links' => [['to_entity_id' => 7, 'to_entity_type' => 'companies']]]]);

        self::assertSame([], $this->links()->findLinkedIds('leads', 10, 'contacts'));
    }

    public function testFindLinkedIdsReturnsEmptyListOnNoContent(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->links()->findLinkedIds('contacts', 5, 'leads'));
    }

    /** @dataProvider unsupportedTypes */
    public function testFindLinkedIdsRejectsUnsupportedTypes(string $entityType, string $targetType): void
    {
        $exception = self::exceptionFrom(fn () => $this->links()->findLinkedIds($entityType, 5, $targetType));

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame([], $this->requests());
    }

    public function unsupportedTypes(): array
    {
        return [
            'сущность' => ['customers', 'leads'],
            'цель' => ['leads', 'customers'],
        ];
    }

    public function testFindMainContactId(): void
    {
        // Так отвечает живая amoCRM: в прочитанных связях признак зовётся main_contact.
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => false]],
            ['to_entity_id' => 6, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => true]],
        ]]]);

        self::assertSame(6, $this->links()->findMainContactId(10));
        self::assertSame(['GET /api/v4/leads/10/links'], $this->requestLog());
    }

    public function testFindMainContactIdReturnsNullWithoutMainContact(): void
    {
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 5, 'to_entity_type' => 'contacts', 'metadata' => ['main_contact' => false]],
            ['to_entity_id' => 6, 'to_entity_type' => 'contacts'],
        ]]]);

        self::assertNull($this->links()->findMainContactId(10));
    }

    public function testFindMainContactIdIgnoresOtherLinkTypes(): void
    {
        // Признак main_contact у связи с компанией контактом её не делает.
        $this->respond(['_embedded' => ['links' => [
            ['to_entity_id' => 7, 'to_entity_type' => 'companies', 'metadata' => ['main_contact' => true]],
        ]]]);

        self::assertNull($this->links()->findMainContactId(10));
    }

    private function links(): Link
    {
        return $this->amocrm()->links();
    }
}
