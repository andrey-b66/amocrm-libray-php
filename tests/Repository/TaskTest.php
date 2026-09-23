<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\Task;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

final class TaskTest extends FakeAmocrmTestCase
{
    public function testCreateForEntityAttachesTask(): void
    {
        $this->respond(self::page('tasks', [['id' => 3]]));

        $task = $this->tasks()->createForEntity('leads', 10, ['text' => 'Перезвонить', 'complete_till' => 1700000000]);

        self::assertSame(['id' => 3], $task);
        self::assertSame(['POST /api/v4/tasks'], $this->requestLog());
        self::assertSame([[
            'text' => 'Перезвонить',
            'complete_till' => 1700000000,
            'entity_type' => 'leads',
            'entity_id' => 10,
        ]], $this->lastRequest()['body']);
    }

    public function testCreateForEntityReturnsEmptyArrayWhenResponseHasNoTask(): void
    {
        $this->respond([]);

        self::assertSame([], $this->tasks()->createForEntity('leads', 10, ['text' => 'Перезвонить']));
    }

    public function testFindForEntityAddsEntityFilter(): void
    {
        $this->respond(self::page('tasks', [['id' => 3]]));

        self::assertSame([['id' => 3]], $this->tasks()->findForEntity('leads', 10, 'filter[is_completed]=0'));
        self::assertSame(
            ['GET /api/v4/tasks?filter[is_completed]=0&filter[entity_type]=leads&filter[entity_id]=10&page=1&limit=250'],
            $this->requestLog(),
        );
    }

    public function testFindForEntityWithoutQueryReadsAllPages(): void
    {
        $this->respond(self::page('tasks', [['id' => 3]], true));
        $this->respond(self::page('tasks', [['id' => 4]]));

        self::assertSame([['id' => 3], ['id' => 4]], $this->tasks()->findForEntity('contacts', 5, '', 250, null));
        self::assertSame([
            'GET /api/v4/tasks?filter[entity_type]=contacts&filter[entity_id]=5&page=1&limit=250',
            'GET /api/v4/tasks?filter[entity_type]=contacts&filter[entity_id]=5&page=2&limit=250',
        ], $this->requestLog());
    }

    public function testRejectsUnsupportedEntityType(): void
    {
        $exception = self::exceptionFrom(fn () => $this->tasks()->createForEntity('customers', 10, []));

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertSame([], $this->requests());
    }

    private function tasks(): Task
    {
        return $this->amocrm()->tasks();
    }
}
