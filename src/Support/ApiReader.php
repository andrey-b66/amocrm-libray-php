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

    /** Получить одну сущность; null, если её нет — на это amoCRM отвечает 204 или 404. */
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
     * Прочитать коллекцию — одну страницу, несколько или все; правила запроса
     * описаны у AbstractApiRepository::find().
     *
     * @param int|null $pages сколько страниц прочитать; null — все
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

            // Хватит: страница пустая, прочитано сколько просили или amoCRM не дала ссылку дальше.
            if ($pageEntities === [] || $page === $pages || !isset($response['_links']['next']['href'])) {
                return $entities;
            }

            $page++;
        }
    }

    /**
     * Дописать к запросу связанные сущности из `with`, если они заданы.
     *
     * Пример: appendWith('page=1', 'contacts') → 'page=1&with=contacts'
     */
    public static function appendWith(string $query, string $with): string
    {
        $with = trim($with);

        if ($with === '') {
            return $query;
        }

        return $query === '' ? "with=$with" : "$query&with=$with";
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

    /** Экземпляры не нужны: у класса только статические методы. */
    private function __construct()
    {
    }
}
