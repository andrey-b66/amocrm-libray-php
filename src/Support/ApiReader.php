<?php

declare(strict_types=1);

namespace Amocrm\Support;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use InvalidArgumentException;

/**
 * Чтение из amoCRM, общее для репозиториев: одна сущность или страницы коллекции.
 *
 * @internal
 */
final class ApiReader
{
    /** Больше 250 сущностей за один запрос amoCRM не отдаёт. */
    public const MAX_PAGE_SIZE = 250;

    /**
     * Получить одну сущность или null, если её нет.
     *
     * Отсутствующие сделку, контакт или примечание amoCRM отдаёт как HTTP 204
     * без тела, а отсутствующего пользователя — как 404: оба ответа значат
     * «не найдено».
     */
    public static function one(ApiClient $client, string $endpoint, string $query = ''): ?array
    {
        try {
            $entity = $client->get($endpoint, $query);
        } catch (ApiException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $entity === [] ? null : $entity;
    }

    /**
     * Прочитать коллекцию — одну страницу, несколько или все.
     *
     * Запрос принимается строкой параметров или целым URL — всё до `?`
     * отбрасывается. `page` и `limit` из строки убираются: их задают аргументы.
     *
     * Сколько читать, задаёт `$pages`: число — столько страниц, `null` — до
     * конца выборки. Обход в любом случае останавливается на пустой странице
     * или когда amoCRM перестаёт отдавать ссылку `_links.next`.
     *
     * @param int|null $pages сколько страниц прочитать; null — все до конца выборки
     */
    public static function pages(
        ApiClient $client,
        string $endpoint,
        string $embeddedKey,
        string $query,
        int $limit,
        ?int $pages
    ): array {
        if ($pages !== null && $pages < 1) {
            throw new InvalidArgumentException(
                'Читать нужно хотя бы одну страницу, передано: ' . $pages . '.',
            );
        }

        $entities = [];
        $page = 1;

        while (true) {
            $response = $client->get($endpoint, self::buildListQuery($query, $page, $limit));
            $pageEntities = $response['_embedded'][$embeddedKey] ?? [];

            foreach ($pageEntities as $entity) {
                $entities[] = $entity;
            }

            // Страница пришла пустая — читать дальше нечего.
            if ($pageEntities === []) {
                break;
            }

            // Прочитали столько страниц, сколько просили.
            if ($pages !== null && $page >= $pages) {
                break;
            }

            // amoCRM перестала давать ссылку на следующую страницу.
            if (!isset($response['_links']['next']['href'])) {
                break;
            }

            $page++;
        }

        return $entities;
    }

    /** Дополнить запрос пагинацией, выбросив из него адрес и прежние `page` и `limit`. */
    private static function buildListQuery(string $query, int $page, int $limit): string
    {
        $query = trim($query);
        $questionMarkPosition = strpos($query, '?');

        if ($questionMarkPosition !== false) {
            $query = substr($query, $questionMarkPosition + 1);
        }

        $parts = [];

        foreach (explode('&', $query) as $part) {
            $part = trim($part);

            if ($part === '' || strpos($part, 'page=') === 0 || strpos($part, 'limit=') === 0) {
                continue;
            }

            $parts[] = $part;
        }

        $parts[] = "page=$page";
        $parts[] = "limit=$limit";

        return implode('&', $parts);
    }

    private function __construct()
    {
    }
}
