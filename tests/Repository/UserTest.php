<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\User;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;

final class UserTest extends FakeAmocrmTestCase
{
    public function testGetAllReadsAllPages(): void
    {
        $this->respond(self::page('users', [['id' => 1]], true));
        $this->respond(self::page('users', [['id' => 2]]));

        self::assertSame([['id' => 1], ['id' => 2]], $this->users()->getAll());
        self::assertSame([
            'GET /api/v4/users?page=1&limit=250',
            'GET /api/v4/users?page=2&limit=250',
        ], $this->requestLog());
    }

    public function testGetAllStopsOnEmptyPageEvenWithNextLink(): void
    {
        $this->respond(self::page('users', [['id' => 1]], true));
        $this->respond(self::page('users', [], true));

        self::assertSame([['id' => 1]], $this->users()->getAll());
        self::assertCount(2, $this->requests());
    }

    public function testGetActiveAndDeactivated(): void
    {
        $users = [
            ['id' => 1, 'rights' => ['is_active' => true]],
            ['id' => 2, 'rights' => ['is_active' => false]],
            ['id' => 3, 'rights' => []],
        ];
        $this->respond(self::page('users', $users));
        $this->respond(self::page('users', $users));

        self::assertSame([$users[0]], $this->users()->getActive());
        // Пользователь без признака активности не попадает ни в один из списков.
        self::assertSame([$users[1]], $this->users()->getDeactivated());
    }

    public function testFindById(): void
    {
        $this->respond(['id' => 1, 'name' => 'Менеджер']);

        self::assertSame(['id' => 1, 'name' => 'Менеджер'], $this->users()->findById(1));
        self::assertSame(['GET /api/v4/users/1'], $this->requestLog());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->respond([], 404);

        self::assertNull($this->users()->findById(1));
    }

    private function users(): User
    {
        return $this->amocrm()->users();
    }
}
