<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;

/**
 * Репозиторий пользователей аккаунта amoCRM. Активность — в `rights.is_active`.
 *
 * $with — связанные данные через запятую: `role`, `group`, `uuid`, `amojo_id`,
 * `user_rank`, `phone_number`.
 */
final class User
{
    private const ENDPOINT = 'api/v4/users';

    private ApiClient $client;

    /** Обычно репозиторий берут у фасада: $amocrm->users(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Получить всех пользователей со всех страниц.
     *
     * Пример: findAll('role,group')
     */
    public function findAll(string $with = ''): array
    {
        return ApiReader::pages(
            $this->client,
            self::ENDPOINT,
            'users',
            ApiReader::appendWith('', $with),
            ApiReader::MAX_PAGE_SIZE,
            null,
        );
    }

    /** Получить активных пользователей. */
    public function findActive(string $with = ''): array
    {
        return $this->filterByActivity(true, $with);
    }

    /** Получить деактивированных пользователей. */
    public function findDeactivated(string $with = ''): array
    {
        return $this->filterByActivity(false, $with);
    }

    /** Найти пользователя по ID; null, если его нет. */
    public function findById(int $userId, string $with = ''): ?array
    {
        return ApiReader::one($this->client, self::ENDPOINT . '/' . $userId, ApiReader::appendWith('', $with));
    }

    /**
     * Отобрать пользователей по `rights.is_active`: фильтра по активности у amoCRM
     * нет. Без этого признака пользователь не попадает ни в один список.
     */
    private function filterByActivity(bool $isActive, string $with): array
    {
        $users = [];

        foreach ($this->findAll($with) as $user) {
            if (($user['rights']['is_active'] ?? null) === $isActive) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
