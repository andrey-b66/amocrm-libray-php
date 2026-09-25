<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Support\ApiReader;
use Amocrm\Support\ApiWriter;

/**
 * Базовый репозиторий коллекции amoCRM: создание, чтение и обновление.
 *
 * Данные перед отправкой не проверяются: ошибку в них amoCRM возвращает как HTTP 400,
 * и она приходит ApiException.
 */
abstract class AbstractApiRepository
{
    /** Сколько ID уходит в одном запросе findByIds(). */
    private const MAX_IDS_PER_REQUEST = 25;

    /** Клиент API, общий для всех репозиториев фасада. */
    protected ApiClient $client;

    /** Путь API без начального слеша, например `api/v4/leads`. */
    abstract protected function endpoint(): string;

    /** Ключ коллекции в поле `_embedded` ответа amoCRM, например `leads`. */
    abstract protected function embeddedKey(): string;

    /** Обычно репозиторий берут у фасада: $amocrm->leads(). */
    public function __construct(ApiClient $apiClient)
    {
        $this->client = $apiClient;
    }

    /**
     * Создать сущности: принимает список и возвращает список созданных с `id`.
     *
     * Список уходит порциями по 250. Своё поле `request_id` amoCRM возвращает рядом
     * с `id` — по нему созданное сопоставляют с исходным. При ошибке прошлые порции
     * уже созданы, следующие не отправлены.
     *
     * Пример: create([['name' => 'Иван', 'request_id' => '42'], ['name' => 'Пётр', 'request_id' => '43']])
     */
    public function create(array $entities): array
    {
        return ApiWriter::write($this->client, 'post', $this->endpoint(), $this->embeddedKey(), $entities);
    }

    /**
     * Найти сущность по ID; null, если её нет.
     *
     * Пример: findById(15, 'leads,companies')
     */
    public function findById(int $id, string $with = ''): ?array
    {
        return ApiReader::one($this->client, $this->endpoint() . '/' . $id, ApiReader::appendWith('', $with));
    }

    /**
     * Получить сущности по запросу: по умолчанию первую страницу (до 250), с $pages = null — все.
     *
     * Запрос — строка параметров или целый URL; `page` и `limit` задают аргументы.
     * Незнакомый фильтр amoCRM не отклоняет, а отдаёт всю выборку. При обходе
     * нескольких страниц добавляйте `order[id]=asc`, чтобы записи не сдвигались.
     *
     * Пример: find('filter[pipeline_id][0]=10739150&with=contacts') — первая страница
     * Пример: find('filter[pipeline_id][0]=10739150&order[id]=asc', 250, 3) — три страницы
     * Пример: find('filter[statuses][0][pipeline_id]=10739150&filter[statuses][0][status_id]=143', 250, null) — все
     *
     * @param int|null $pages сколько страниц прочитать; null — все
     */
    public function find(
        string $query = '',
        int $limit = ApiReader::MAX_PAGE_SIZE,
        ?int $pages = 1
    ): array {
        return ApiReader::pages($this->client, $this->endpoint(), $this->embeddedKey(), $query, $limit, $pages);
    }

    /**
     * Получить сущности по списку ID: в порядке ID, без дублей и отсутствующих.
     * Список уходит порциями по 25 ID.
     *
     * Пример: findByIds([10, 20, 30], 'leads,companies')
     */
    public function findByIds(array $ids, string $with = ''): array
    {
        $ids = array_unique($ids);
        $entitiesById = [];

        foreach (array_chunk($ids, self::MAX_IDS_PER_REQUEST) as $chunk) {
            $query = '';

            foreach ($chunk as $index => $id) {
                $query .= "filter[id][$index]=" . (int) $id . '&';
            }

            $query .= 'page=1&limit=' . count($chunk);

            $response = $this->client->get($this->endpoint(), ApiReader::appendWith($query, $with));

            foreach ($response['_embedded'][$this->embeddedKey()] ?? [] as $entity) {
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
     * Обновить сущности: принимает список и возвращает список обновлённых.
     *
     * `id` у каждой обязателен, остальные поля меняются только перечисленные.
     * Список уходит порциями по 250; при ошибке прошлые порции уже записаны.
     *
     * Пример: update([['id' => 10, 'price' => 1000], ['id' => 20, 'name' => 'Пётр']])
     */
    public function update(array $entities): array
    {
        return ApiWriter::write($this->client, 'patch', $this->endpoint(), $this->embeddedKey(), $entities);
    }
}
