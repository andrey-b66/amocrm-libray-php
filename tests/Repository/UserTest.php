<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\User;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;

/** Пользователи аккаунта: все, активные и деактивированные. */
final class UserTest extends FakeAmocrmTestCase
{
    public function testFindAllReadsAllPages(): void
    {
        $this->respond(self::page('users', [['id' => 1]], true));
        $this->respond(self::page('users', [['id' => 2]]));

        self::assertSame([['id' => 1], ['id' => 2]], $this->users()->findAll());
        self::assertSame([
            'GET /api/v4/users?page=1&limit=250',
            'GET /api/v4/users?page=2&limit=250',
        ], $this->requestLog());
    }

    public function testFindAllPassesWith(): void
    {
        $this->respond(self::page('users', [['id' => 1]]));

        $this->users()->findAll(' role,group ');

        self::assertSame(['GET /api/v4/users?with=role,group&page=1&limit=250'], $this->requestLog());
    }

    public function testFindAllStopsOnEmptyPageEvenWithNextLink(): void
    {
        $this->respond(self::page('users', [['id' => 1]], true));
        $this->respond(self::page('users', [], true));

        self::assertSame([['id' => 1]], $this->users()->findAll());
        self::assertCount(2, $this->requests());
    }

    public function testFindActiveAndDeactivated(): void
    {
        $users = [
            ['id' => 1, 'rights' => ['is_active' => true]],
            ['id' => 2, 'rights' => ['is_active' => false]],
            ['id' => 3, 'rights' => []],
        ];
        $this->respond(self::page('users', $users));
        $this->respond(self::page('users', $users));

        self::assertSame([$users[0]], $this->users()->findActive('role'));
        // Пользователь без признака активности не попадает ни в один из списков.
        self::assertSame([$users[1]], $this->users()->findDeactivated());
        self::assertSame([
            'GET /api/v4/users?with=role&page=1&limit=250',
            'GET /api/v4/users?page=1&limit=250',
        ], $this->requestLog());
    }

    public function testFindById(): void
    {
        $this->respond(['id' => 1, 'name' => 'Менеджер']);

        self::assertSame(['id' => 1, 'name' => 'Менеджер'], $this->users()->findById(1, 'role,group'));
        self::assertSame(['GET /api/v4/users/1?with=role,group'], $this->requestLog());
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
