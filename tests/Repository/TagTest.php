<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\Tag;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

final class TagTest extends FakeAmocrmTestCase
{
    public function testFindReadsTagDictionary(): void
    {
        $this->respond(['_embedded' => ['tags' => [['id' => 5, 'name' => 'Важная заявка']]]]);

        self::assertSame(
            [['id' => 5, 'name' => 'Важная заявка']],
            $this->tags()->find('leads', 'query=Важная', 50),
        );
        self::assertSame(['GET /api/v4/leads/tags?query=Важная&page=1&limit=50'], $this->requestLog());
    }

    public function testFindReadsAllPages(): void
    {
        $this->respond(self::page('tags', [['id' => 5]], true));
        $this->respond(self::page('tags', [['id' => 6]]));

        self::assertSame([['id' => 5], ['id' => 6]], $this->tags()->find('leads', '', 250, null));
        self::assertSame([
            'GET /api/v4/leads/tags?page=1&limit=250',
            'GET /api/v4/leads/tags?page=2&limit=250',
        ], $this->requestLog());
    }

    public function testFindWithoutQuery(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->tags()->find('contacts'));
        self::assertSame(['GET /api/v4/contacts/tags?page=1&limit=250'], $this->requestLog());
    }

    public function testCreate(): void
    {
        $this->respond(['_embedded' => ['tags' => [['id' => 5, 'name' => 'Важная заявка']]]]);

        self::assertSame(['id' => 5, 'name' => 'Важная заявка'], $this->tags()->create('leads', ['name' => 'Важная заявка']));
        self::assertSame(['POST /api/v4/leads/tags'], $this->requestLog());
        self::assertSame([['name' => 'Важная заявка']], $this->lastRequest()['body']);
    }

    public function testAddToEntityAcceptsIdsNamesAndRawTags(): void
    {
        $this->respond(['id' => 10]);

        $result = $this->tags()->addToEntity('leads', 10, [5, 'Повторный клиент', ['id' => 6], '15']);

        self::assertSame(['id' => 10], $result);
        self::assertSame(['PATCH /api/v4/leads/10'], $this->requestLog());
        // Числовая строка — это название тега, а не ID.
        self::assertSame(['tags_to_add' => [
            ['id' => 5],
            ['name' => 'Повторный клиент'],
            ['id' => 6],
            ['name' => '15'],
        ]], $this->lastRequest()['body']);
    }

    public function testRemoveFromEntity(): void
    {
        $this->respond(['id' => 10]);

        $this->tags()->removeFromEntity('companies', 7, [5]);

        self::assertSame(['PATCH /api/v4/companies/7'], $this->requestLog());
        self::assertSame(['tags_to_delete' => [['id' => 5]]], $this->lastRequest()['body']);
    }

    public function testClearForEntity(): void
    {
        $this->respond(['id' => 10]);

        $this->tags()->clearForEntity('leads', 10);

        self::assertSame(['PATCH /api/v4/leads/10'], $this->requestLog());
        self::assertSame('{"_embedded":{"tags":null}}', $this->lastRequest()['rawBody']);
    }

    public function testRejectsUnsupportedEntityType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->tags()->addToEntity('customers', 10, [5]);
    }

    private function tags(): Tag
    {
        return $this->amocrm()->tags();
    }
}
