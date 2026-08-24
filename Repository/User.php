<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;

/** Репозиторий пользователей аккаунта amoCRM. */
final class User
{
    private const ENDPOINT = 'api/v4/users';
    private const PAGE_SIZE = 250;

    private ApiClient $request;

    public function __construct(ApiClient $apiClient)
    {
        $this->request = $apiClient;
    }

    /** Получить всех пользователей со всех страниц API. Активность — в `rights.is_active`. */
    public function getAll(): array
    {
        $users = [];
        $page = 1;

        do {
            $response = $this->request->get(self::ENDPOINT, "page=$page&limit=" . self::PAGE_SIZE);
            $pageUsers = array_values($response['_embedded']['users'] ?? []);

            foreach ($pageUsers as $user) {
                $users[] = $user;
            }

            $hasNextPage = $pageUsers !== [] && isset($response['_links']['next']['href']);
            $page++;
        } while ($hasNextPage);

        return $users;
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
        try {
            $user = $this->request->get(self::ENDPOINT . '/' . $userId);
        } catch (ApiException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $user === [] ? null : $user;
    }

    private function filterByActivity(bool $isActive): array
    {
        return array_values(array_filter(
            $this->getAll(),
            static fn (array $user): bool => ($user['rights']['is_active'] ?? null) === $isActive,
        ));
    }
}
