<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;

/** Репозиторий пользователей аккаунта amoCRM. */
final class User
{
    private const ENDPOINT = 'api/v4/users';

    private ApiClient $client;

    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /** Получить всех пользователей со всех страниц API. Активность — в `rights.is_active`. */
    public function getAll(): array
    {
        return ApiReader::pages($this->client, self::ENDPOINT, 'users', '', ApiReader::MAX_PAGE_SIZE, null);
    }

    public function getActive(): array
    {
        return $this->filterByActivity(true);
    }

    public function getDeactivated(): array
    {
        return $this->filterByActivity(false);
    }

    /** Найти пользователя по ID. Возвращает null, если пользователя нет. */
    public function findById(int $userId): ?array
    {
        return ApiReader::one($this->client, self::ENDPOINT . '/' . $userId);
    }

    private function filterByActivity(bool $isActive): array
    {
        $users = [];

        foreach ($this->getAll() as $user) {
            if (($user['rights']['is_active'] ?? null) === $isActive) {
                $users[] = $user;
            }
        }

        return $users;
    }
}
