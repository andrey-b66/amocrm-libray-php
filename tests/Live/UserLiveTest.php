<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/** Пользователи на живом аккаунте: только чтение, ничего не создаёт. */
final class UserLiveTest extends LiveTestCase
{
    public function testActiveAndDeactivatedSplitAllUsers(): void
    {
        $users = $this->amocrm()->users();
        $active = $users->findActive();
        $deactivated = $users->findDeactivated();

        self::assertNotSame([], $active);
        self::assertSame([true], array_values(array_unique(array_column(array_column($active, 'rights'), 'is_active'))));
        self::assertNotContains(true, array_column(array_column($deactivated, 'rights'), 'is_active'));
        self::assertEqualsCanonicalizing(
            array_column($users->findAll(), 'id'),
            array_merge(array_column($active, 'id'), array_column($deactivated, 'id')),
        );
    }

    public function testFindByIdWithRoleAndGroup(): void
    {
        $userId = $this->amocrm()->users()->findActive()[0]['id'];

        $user = $this->amocrm()->users()->findById($userId, 'role,group');

        self::assertSame($userId, $user['id'] ?? null);
        self::assertArrayHasKey('roles', $user['_embedded'] ?? []);
        self::assertArrayHasKey('groups', $user['_embedded'] ?? []);
    }

    public function testFindByIdReturnsNullForMissingUser(): void
    {
        // В отличие от сделок, на несуществующего пользователя amoCRM отвечает 404.
        self::assertNull($this->amocrm()->users()->findById(999999999));
    }
}
