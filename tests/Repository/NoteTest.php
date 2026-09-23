<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\Note;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

final class NoteTest extends FakeAmocrmTestCase
{
    public function testCreateAttachesNoteToEntity(): void
    {
        $this->respond(['_embedded' => ['notes' => [['id' => 77]]]]);

        $note = $this->notes()->create('leads', 10, [
            'note_type' => 'call_in',
            'params' => ['uniq' => 'call-1', 'duration' => 60],
        ]);

        self::assertSame(['id' => 77], $note);
        self::assertSame(['POST /api/v4/leads/notes'], $this->requestLog());
        self::assertSame([[
            'note_type' => 'call_in',
            'params' => ['uniq' => 'call-1', 'duration' => 60],
            'entity_id' => 10,
        ]], $this->lastRequest()['body']);
    }

    public function testCreateCommonTrimsText(): void
    {
        $this->respond(['_embedded' => ['notes' => [['id' => 77]]]]);

        $this->notes()->createCommon('contacts', 5, '  Клиент просил перезвонить  ');

        self::assertSame(['POST /api/v4/contacts/notes'], $this->requestLog());
        self::assertSame([[
            'note_type' => 'common',
            'params' => ['text' => 'Клиент просил перезвонить'],
            'entity_id' => 5,
        ]], $this->lastRequest()['body']);
    }

    public function testCreateReturnsEmptyArrayWhenResponseHasNoNote(): void
    {
        $this->respond([]);

        self::assertSame([], $this->notes()->createCommon('leads', 10, 'Текст'));
    }

    /** @dataProvider noteQueries */
    public function testFindForEntityAddsPagination(string $query, int $limit, string $expectedQuery): void
    {
        $this->respond(['_embedded' => ['notes' => [['id' => 77]]]]);

        self::assertSame([['id' => 77]], $this->notes()->findForEntity('leads', 10, $query, $limit));
        self::assertSame(["GET /api/v4/leads/10/notes?$expectedQuery"], $this->requestLog());
    }

    public function noteQueries(): array
    {
        return [
            'без запроса' => ['', 250, 'page=1&limit=250'],
            'с фильтром' => ['filter[note_type][0]=common', 250, 'filter[note_type][0]=common&page=1&limit=250'],
            'амперсанды по краям' => [' &filter[note_type][0]=common& ', 50, 'filter[note_type][0]=common&page=1&limit=50'],
            'целый URL с page и limit' => [
                'https://example.amocrm.ru/leads/10/notes?filter[note_type][0]=common&page=3&limit=10',
                50,
                'filter[note_type][0]=common&page=1&limit=50',
            ],
        ];
    }

    public function testFindForEntityReadsAllPages(): void
    {
        $this->respond(self::page('notes', [['id' => 1]], true));
        $this->respond(self::page('notes', [['id' => 2]]));

        self::assertSame([['id' => 1], ['id' => 2]], $this->notes()->findForEntity('leads', 10, '', 250, null));
        self::assertSame([
            'GET /api/v4/leads/10/notes?page=1&limit=250',
            'GET /api/v4/leads/10/notes?page=2&limit=250',
        ], $this->requestLog());
    }

    public function testFindForEntityReturnsEmptyListOnNoContent(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->notes()->findForEntity('leads', 10));
    }

    public function testFindById(): void
    {
        $this->respond(['id' => 77, 'note_type' => 'common']);

        self::assertSame(['id' => 77, 'note_type' => 'common'], $this->notes()->findById('leads', 10, 77));
        self::assertSame(['GET /api/v4/leads/10/notes/77'], $this->requestLog());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        // На несуществующее примечание amoCRM отвечает 204 без тела.
        $this->respondNoContent();

        self::assertNull($this->notes()->findById('leads', 10, 77));
    }

    public function testUpdate(): void
    {
        $this->respond(['id' => 77, 'updated_at' => 1700000000]);

        $result = $this->notes()->update('leads', 10, 77, ['params' => ['text' => 'Новый текст']]);

        self::assertSame(['id' => 77, 'updated_at' => 1700000000], $result);
        self::assertSame(['PATCH /api/v4/leads/10/notes/77'], $this->requestLog());
        self::assertSame(['params' => ['text' => 'Новый текст']], $this->lastRequest()['body']);
    }

    public function testRejectsUnsupportedEntityType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->notes()->findForEntity('customers', 10);
    }

    private function notes(): Note
    {
        return $this->amocrm()->notes();
    }
}
