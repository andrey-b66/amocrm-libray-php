<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use Amocrm\Support\FormattedNumberPhone;

/**
 * Базовый репозиторий коллекций amoCRM с обычными CRUD-операциями.
 *
 * Принимает и возвращает обычные массивы формата amoCRM API v4. Данные перед
 * отправкой не проверяются: на некорректный запрос amoCRM отвечает HTTP-ошибкой,
 * которая приходит как ApiException с полем `detail`.
 */
abstract class AbstractApiRepository
{
    protected const MAX_PAGE_SIZE = 250;

    protected ApiClient $request;

    /** Путь API без начального слеша. */
    abstract protected function endpoint(): string;

    /** Ключ коллекции внутри поля `_embedded` ответа amoCRM. */
    abstract protected function embeddedKey(): string;

    public function __construct(ApiClient $apiClient)
    {
        $this->request = $apiClient;
    }

    /**
     * Создать одну сущность по данным формата amoCRM API v4.
     *
     * Пример: create(['name' => 'Иван', 'custom_fields_values' => [...]])
     */
    public function create(array $data): array
    {
        $response = $this->request->post($this->endpoint(), [$data]);

        return $response['_embedded'][$this->embeddedKey()][0] ?? [];
    }

    /**
     * Найти сущность по ID. Возвращает null, если сущности нет.
     *
     * Пример: findById(15, 'leads,companies')
     */
    public function findById(int $id, string $with = ''): ?array
    {
        $with = trim($with);

        try {
            $entity = $this->request->get(
                $this->endpoint() . '/' . $id,
                $with === '' ? '' : 'with=' . $with,
            );
        } catch (ApiException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return $entity === [] ? null : $entity;
    }

    /**
     * Получить одну страницу сущностей.
     *
     * Запрос пишется обычной строкой, как в адресной строке браузера: можно
     * вставить и целиком URL, всё до `?` отбросится. `page` и `limit` из
     * строки игнорируются — их задают аргументы метода.
     *
     * Пример: find('filter[pipeline_id][0]=10739150&filter[status_id][0]=143&with=contacts')
     * Пример: find('https://my.amocrm.ru/leads?filter[created_at][from]=1753747200', 2, 50)
     */
    public function find(
        string $query = '',
        int $page = 1,
        int $limit = self::MAX_PAGE_SIZE,
    ): array {
        return $this->getEntitiesPage($this->buildListQuery($query, $page, $limit))[0];
    }

    /**
     * Получить сразу несколько страниц — запросы к amoCRM уходят одновременно.
     *
     * Возвращает массив «номер страницы => список сущностей» в порядке
     * переданных номеров. Пустой список означает, что страница вышла за край
     * выборки: дальше данных нет. Страниц передают сколько нужно — в полёте
     * держится семь, это лимит amoCRM, а освободившееся место сразу занимает
     * следующая страница.
     *
     * Порядок выдачи между запросами amoCRM не закрепляет, поэтому для
     * постраничной выгрузки в запрос стоит добавить `order[id]=asc`: иначе
     * сделка, изменившаяся во время обхода, может попасть в две страницы сразу
     * или не попасть ни в одну.
     *
     * Пример: findPages('filter[status_id][0]=143&order[id]=asc', [1, 2, 3])
     *
     * @param int[] $pages номера страниц, нумерация с единицы
     * @return array<int, array>
     */
    public function findPages(
        string $query,
        array $pages,
        int $limit = self::MAX_PAGE_SIZE,
    ): array {
        $queries = [];

        foreach ($pages as $page) {
            $page = (int) $page;
            $queries[$page] = $this->buildListQuery($query, $page, $limit);
        }

        $entitiesByPage = [];

        foreach ($this->request->getMany($this->endpoint(), $queries) as $page => $response) {
            // Пачка отдаёт ошибку значением, а репозиторий — исключением, как везде.
            if ($response instanceof ApiException) {
                throw $response;
            }

            $entitiesByPage[$page] = array_values($response['_embedded'][$this->embeddedKey()] ?? []);
        }

        return $entitiesByPage;
    }

    /**
     * Получить все сущности по запросу, обходя страницы автоматически.
     *
     * Страницы читаются по 250 штук, пока amoCRM отдаёт ссылку `_links.next`.
     * Весь результат держится в памяти: для очень больших выборок лучше сузить
     * фильтр или читать постранично через find().
     *
     * Пример: findAll('filter[pipeline_id][0]=10739150&filter[status_id][0]=143')
     * Пример: findAll() — вообще все сущности
     */
    public function findAll(string $query = ''): array
    {
        $entities = [];
        $page = 1;

        do {
            [$pageEntities, $hasNextPage] = $this->getEntitiesPage(
                $this->buildListQuery($query, $page, self::MAX_PAGE_SIZE),
            );

            foreach ($pageEntities as $entity) {
                $entities[] = $entity;
            }

            $page++;
        } while ($hasNextPage);

        return $entities;
    }

    /**
     * Получить сущности по списку ID. Большие списки сами бьются на запросы по 250 ID.
     *
     * Результат идёт в порядке переданных ID. Дубли удаляются, отсутствующие
     * или недоступные сущности в результат не попадают.
     *
     * Пример: findByIds([10, 20, 30], 'leads,companies')
     */
    public function findByIds(array $ids, string $with = ''): array
    {
        $ids = array_values(array_unique($ids));
        $with = trim($with);

        if ($ids === []) {
            return [];
        }

        $entitiesById = [];

        foreach (array_chunk($ids, self::MAX_PAGE_SIZE) as $chunk) {
            $query = '';

            foreach ($chunk as $index => $id) {
                $query .= "filter[id][$index]=" . (int) $id . '&';
            }

            $query .= 'page=1&limit=' . count($chunk);

            if ($with !== '') {
                $query .= '&with=' . $with;
            }

            foreach ($this->getEntitiesPage($query)[0] as $entity) {
                $entityId = $entity['id'] ?? null;

                if (is_int($entityId)) {
                    $entitiesById[$entityId] = $entity;
                }
            }
        }

        $result = [];

        foreach ($ids as $id) {
            if (isset($entitiesById[$id])) {
                $result[] = $entitiesById[$id];
            }
        }

        return $result;
    }

    /**
     * Обновить одну сущность частичными данными формата amoCRM API v4.
     *
     * Пример: update(15, ['name' => 'Пётр'])
     */
    public function update(int $id, array $data): array
    {
        return $this->request->patch($this->endpoint() . '/' . $id, $data);
    }

    /**
     * Найти сущности по точному значению пользовательского поля.
     *
     * Всегда возвращается список: amoCRM не гарантирует уникальность значений
     * пользовательских полей.
     *
     * Пример: findByField(123456, 'ООО Ромашка')
     */
    public function findByField(
        int $fieldId,
        int|float|string|bool $fieldValue,
        int $limit = self::MAX_PAGE_SIZE,
        string $with = '',
    ): array {
        if (is_bool($fieldValue)) {
            $fieldValue = $fieldValue ? '1' : '0';
        }

        $query = "filter[custom_fields_values][$fieldId][0]=" . urlencode(trim((string) $fieldValue));
        $with = trim($with);

        if ($with !== '') {
            $query .= '&with=' . $with;
        }

        return $this->find($query, 1, $limit);
    }

    /**
     * Выполнить полнотекстовый поиск по полям сущности.
     *
     * Пример: findByQuery('Ромашка', 25)
     */
    public function findByQuery(
        string $query,
        int $limit = self::MAX_PAGE_SIZE,
        string $with = '',
    ): array {
        return $this->findBySearchString(trim($query), $limit, $with);
    }

    /**
     * Выполнить полнотекстовый поиск по последним 10 цифрам номера телефона.
     *
     * Пример: findByPhone('+7 (999) 000-00-00')
     */
    public function findByPhone(
        string $phone,
        int $limit = self::MAX_PAGE_SIZE,
        string $with = '',
    ): array {
        return $this->findBySearchString(
            FormattedNumberPhone::getLastTenDigits(trim($phone)),
            $limit,
            $with,
        );
    }

    private function findBySearchString(string $search, int $limit, string $with): array
    {
        // Пустой поиск вернул бы весь аккаунт — считаем, что не найдено.
        if ($search === '') {
            return [];
        }

        $query = 'query=' . urlencode($search);
        $with = trim($with);

        if ($with !== '') {
            $query .= '&with=' . $with;
        }

        return $this->find($query, 1, $limit);
    }

    /**
     * Дополнить запрос пагинацией.
     *
     * Принимается как строка параметров, так и вставленный целиком URL — всё до
     * `?` отбрасывается. `page` и `limit` из строки убираются: их задают
     * аргументы метода.
     */
    protected function buildListQuery(string $query, int $page, int $limit): string
    {
        $query = trim($query);
        $questionMarkPosition = strpos($query, '?');

        if ($questionMarkPosition !== false) {
            $query = substr($query, $questionMarkPosition + 1);
        }

        $parts = [];

        foreach (explode('&', $query) as $part) {
            $part = trim($part);

            if ($part === '' || str_starts_with($part, 'page=') || str_starts_with($part, 'limit=')) {
                continue;
            }

            $parts[] = $part;
        }

        $parts[] = "page=$page";
        $parts[] = "limit=$limit";

        return implode('&', $parts);
    }

    /**
     * Получить одну страницу коллекции вместе с признаком наличия следующей.
     *
     * Возвращает массив из списка сущностей и признака `есть следующая страница`.
     */
    protected function getEntitiesPage(string $query): array
    {
        $response = $this->request->get($this->endpoint(), $query);
        $entities = array_values($response['_embedded'][$this->embeddedKey()] ?? []);
        $hasNextPage = $entities !== [] && isset($response['_links']['next']['href']);

        return [$entities, $hasNextPage];
    }
}
