<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Tests\Fake\FakeAmocrmTestCase;

/** Сделки: выборка незакрытых по списку ID. */
final class LeadTest extends FakeAmocrmTestCase
{
    public function testFindActiveByIdsSkipsClosedLeads(): void
    {
        $this->respond(self::page('leads', [
            ['id' => 1, 'status_id' => 142],
            ['id' => 2, 'status_id' => 555],
            ['id' => 3, 'status_id' => 143],
        ]));

        $leads = $this->amocrm()->leads()->findActiveByIds([1, 2, 3]);

        self::assertSame([['id' => 2, 'status_id' => 555]], $leads);
        self::assertSame(
            ['GET /api/v4/leads?filter[id][0]=1&filter[id][1]=2&filter[id][2]=3&page=1&limit=3'],
            $this->requestLog(),
        );
    }

    public function testFindActiveByIdsWithOwnClosedStatuses(): void
    {
        $this->respond(self::page('leads', [['id' => 1, 'status_id' => 142], ['id' => 2, 'status_id' => 555]]));

        self::assertSame(
            [['id' => 1, 'status_id' => 142]],
            $this->amocrm()->leads()->findActiveByIds([1, 2], '', [555]),
        );
    }

    public function testFindActiveByIdsPassesWith(): void
    {
        $this->respond(self::page('leads', [['id' => 1, 'status_id' => 555]]));

        $leads = $this->amocrm()->leads()->findActiveByIds([1], 'contacts');

        self::assertSame([['id' => 1, 'status_id' => 555]], $leads);
        self::assertSame(
            ['GET /api/v4/leads?filter[id][0]=1&page=1&limit=1&with=contacts'],
            $this->requestLog(),
        );
    }

    public function testFindActiveByIdsWithEmptyListSendsNothing(): void
    {
        self::assertSame([], $this->amocrm()->leads()->findActiveByIds([]));
        self::assertSame([], $this->requests());
    }

    public function testFindActiveByIdsKeepsLeadsWithoutStatus(): void
    {
        // Статуса в ответе нет — сделку не выбрасываем: закрытой она не помечена.
        $this->respond(self::page('leads', [['id' => 1]]));

        self::assertSame([['id' => 1]], $this->amocrm()->leads()->findActiveByIds([1]));
    }
}
