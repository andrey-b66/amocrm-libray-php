<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use InvalidArgumentException;

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

    protected const MAX_IDS_PER_REQUEST = 25;

    /** Сколько сущностей amoCRM принимает в теле одного запроса на запись. */
    protected const MAX_BATCH_SIZE = 250;

    /** Сколько последних цифр номера amoCRM использует при поиске по телефону. */
    private const PHONE_SEARCH_DIGITS = 10;

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
     * Создать сущности по данным формата amoCRM API v4.
     *
     * Принимает список сущностей и возвращает список созданных — даже когда
     * сущность одна: create([['name' => 'Иван']]). Так же, как их принимает
     * сама amoCRM, поэтому одну запись и тысячу создают одним и тем же кодом.
     *
     * Пачка идёт в теле одного запроса: amoCRM принимает до 250 штук за раз,
     * поэтому список любой длины бьётся на порции по 250, и порции уходят одна
     * за другой. Тысяча контактов — это четыре запроса вместо тысячи.
     *
     * На создание amoCRM отдаёт не всю сущность, а только `id`, `request_id` и
     * ссылку на неё. Если нужна созданная запись целиком, её читают потом через
     * findByIds().
     *
     * Порядок возврата задаёт amoCRM. Чтобы связать созданные записи со своими
     * исходными, положите в каждую сущность поле `request_id` — любое своё
     * значение, хоть ID записи в вашей базе. amoCRM его не разбирает, а
     * возвращает обратно рядом с `id`. Без него amoCRM подставит своё значение,
     * и в каждой порции нумерация начнётся заново.
     *
     * Ошибка приходит исключением, но запись при этом всё равно частичная:
     * предыдущие порции уже создались, а следующие не отправятся вовсе.
     *
     * Пример: create([
     *     [
     *         'name' => 'Иван',
     *         'request_id' => '42',
     *         'custom_fields_values' => [
     *             ['field_code' => 'PHONE', 'values' => [['value' => '+79990000000']]],
     *         ],
     *     ],
     *     [
     *         'name' => 'Пётр',
     *         'request_id' => '43',
     *         'custom_fields_values' => [
     *             ['field_id' => 123456, 'values' => [['value' => 'ООО Ромашка']]],
     *         ],
     *     ],
     * ])
     *
     * Ответ: [
     *     ['id' => 40401635, 'request_id' => '42', '_links' => [...]],
     *     ['id' => 40401636, 'request_id' => '43', '_links' => [...]],
     * ]
     */
    public function create(array $entities): array
    {
        // Пустой список — создавать нечего.
        if ($entities === []) {
            return [];
        }

        foreach ($entities as $index => $entity) {
            // Одну сущность тоже передают списком: create([['name' => 'Иван']]).
            if (!is_array($entity)) {
                throw new InvalidArgumentException(
                    'Элемент ' . $index . ' не является массивом данных сущности: '
                    . 'create() принимает список сущностей.',
                );
            }
        }

        $created = [];

        // array_chunk нумерует каждую порцию заново с нуля, и это важно: если
        // во входном списке ключи шли с пропусками (например, после unset),
        // json_encode превратил бы его в объект `{"0":…,"2":…}`, а amoCRM
        // ждёт в теле запроса массив `[…]`.
        foreach (array_chunk($entities, self::MAX_BATCH_SIZE) as $chunk) {
            $response = $this->request->post($this->endpoint(), $chunk);

            foreach ($response['_embedded'][$this->embeddedKey()] ?? [] as $entity) {
                $created[] = $entity;
            }
        }

        return $created;
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
     * Получить сущности по запросу — одну страницу, несколько или все.
     *
     * Запрос пишется обычной строкой, как в адресной строке браузера: можно
     * вставить и целиком URL, всё до `?` отбросится. `page` и `limit` из строки
     * игнорируются — их задают аргументы метода.
     *
     * Сколько читать, задаёт `$pages`: число — столько страниц, `null` — до
     * конца выборки. Обход в любом случае останавливается, когда amoCRM
     * перестаёт отдавать ссылку `_links.next`, поэтому лишних запросов не будет.
     * Возвращается всегда список сущностей.
     *
     * Страницы читаются одна за другой, и весь результат держится в памяти: для
     * очень больших выборок лучше сузить фильтр.
     *
     * Порядок выдачи между запросами amoCRM не закрепляет, поэтому при обходе
     * нескольких страниц в запрос стоит добавить `order[id]=asc`: иначе сделка,
     * изменившаяся во время обхода, может попасть в две страницы сразу или не
     * попасть ни в одну.
     *
     * Пример: find('filter[status_id][0]=143&with=contacts') — первая страница
     * Пример: find('https://my.amocrm.ru/leads?filter[created_at][from]=1753747200', 50)
     * Пример: find('filter[status_id][0]=143&order[id]=asc', 250, 3) — три страницы
     * Пример: find('filter[status_id][0]=143', 250, null) — вообще все сущности
     *
     * @param int|null $pages сколько страниц прочитать; null — все до конца выборки
     */
    public function find(
        string $query = '',
        int $limit = self::MAX_PAGE_SIZE,
        ?int $pages = 1
    ): array {
        if ($pages !== null && $pages < 1) {
            throw new InvalidArgumentException(
                'Читать нужно хотя бы одну страницу, передано: ' . $pages . '.',
            );
        }

        $entities = [];
        $page = 1;
        $pagesRead = 0;

        while (true) {
            $response = $this->request->get(
                $this->endpoint(),
                $this->buildListQuery($query, $page, $limit),
            );

            // Коллекция лежит внутри `_embedded` под ключом сущности: leads,
            // contacts, elements. На пустую выборку amoCRM отвечает 204 без
            // тела, поэтому `_embedded` в ответе может не оказаться вовсе.
            $pageEntities = $response['_embedded'][$this->embeddedKey()] ?? [];

            foreach ($pageEntities as $entity) {
                $entities[] = $entity;
            }

            $pagesRead++;

            // Страница пришла пустая — читать дальше нечего.
            if ($pageEntities === []) {
                break;
            }

            // Прочитали столько страниц, сколько просили.
            if ($pages !== null && $pagesRead >= $pages) {
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

    /**
     * Получить сущности по списку ID. Большие списки сами бьются на порции по 25 ID.
     *
     * Порции уходят к amoCRM одна за другой, поэтому чем длиннее список, тем
     * дольше ответ.
     *
     * Результат идёт в порядке переданных ID. Дубли удаляются, отсутствующие
     * или недоступные сущности в результат не попадают.
     *
     * Пример: findByIds([10, 20, 30], 'leads,companies')
     */
    public function findByIds(array $ids, string $with = ''): array
    {
        $ids = array_unique($ids);
        $with = trim($with);

        if ($ids === []) {
            return [];
        }

        $entitiesById = [];

        foreach (array_chunk($ids, self::MAX_IDS_PER_REQUEST) as $chunk) {
            $query = '';

            foreach ($chunk as $index => $id) {
                $query .= "filter[id][$index]=" . (int) $id . '&';
            }

            $query .= 'page=1&limit=' . count($chunk);

            if ($with !== '') {
                $query .= '&with=' . $with;
            }

            $response = $this->request->get($this->endpoint(), $query);

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
     * Обновить сущности частичными данными формата amoCRM API v4.
     *
     * Принимает список сущностей и возвращает список обновлённых — даже когда
     * сущность одна: update([['id' => 15, 'name' => 'Пётр']]). Так же, как их
     * принимает сама amoCRM, поэтому одну запись и тысячу правят одним кодом.
     *
     * У каждой сущности должен быть свой `id` внутри данных — по нему amoCRM и
     * находит запись. Остальные поля частичные: перечисляют только то, что
     * меняется, остальное остаётся как было.
     *
     * Пачка идёт в теле одного запроса: amoCRM принимает до 250 штук за раз,
     * поэтому список любой длины бьётся на порции по 250, и порции уходят одна
     * за другой. Тысяча сделок — это четыре запроса вместо тысячи.
     *
     * Возвращает обновлённые сущности в том виде, в каком их вернула amoCRM.
     * Порядок задаёт amoCRM, поэтому искать в результате нужную стоит по `id`.
     *
     * Ошибка приходит исключением, но запись при этом всё равно частичная:
     * предыдущие порции уже записались, в упавшей amoCRM применила годные
     * сущности, а следующие порции не отправятся вовсе. Поэтому исключение
     * здесь означает «прошло не всё», а не «не прошло ничего». Непринятые
     * записи перечислены у ApiException в getResponseData() под своими ID:
     * `['errors' => [999999999 => 'Lead not found']]`.
     *
     * Пример: update([
     *     ['id' => 10, 'price' => 1000],
     *     ['id' => 20, 'name' => 'Пётр'],
     * ])
     *
     * Ответ: [
     *     ['id' => 10, 'name' => 'Заявка с сайта', 'price' => 1000, ...],
     *     ['id' => 20, 'name' => 'Пётр', ...],
     * ]
     */
    public function update(array $entities): array
    {
        // Пустой список — обновлять нечего.
        if ($entities === []) {
            return [];
        }

        foreach ($entities as $index => $entity) {
            // Одну сущность тоже передают списком: update([['id' => 15, ...]]).
            if (!is_array($entity)) {
                throw new InvalidArgumentException(
                    'Элемент ' . $index . ' не является массивом данных сущности: '
                    . 'update() принимает список сущностей.',
                );
            }

            // Без `id` amoCRM молча создала бы новую сущность вместо обновления.
            if (!isset($entity['id'])) {
                throw new InvalidArgumentException(
                    'В сущности ' . $index . ' не указан ID обновляемой записи.',
                );
            }
        }

        $updated = [];

        // array_chunk нумерует каждую порцию заново с нуля, и это важно: если
        // во входном списке ключи шли с пропусками (например, после unset),
        // json_encode превратил бы его в объект `{"0":…,"2":…}`, а amoCRM
        // ждёт в теле запроса массив `[…]`.
        foreach (array_chunk($entities, self::MAX_BATCH_SIZE) as $chunk) {
            $response = $this->request->patch($this->endpoint(), $chunk);

            foreach ($response['_embedded'][$this->embeddedKey()] ?? [] as $entity) {
                $updated[] = $entity;
            }
        }

        return $updated;
    }

    /**
     * Найти сущности по точному значению пользовательского поля.
     *
     * Всегда возвращается список: amoCRM не гарантирует уникальность значений
     * пользовательских полей.
     *
     * Пример: findByField(123456, 'ООО Ромашка')
     *
     * @param int|float|string|bool $fieldValue
     */
    public function findByField(
        int $fieldId,
        $fieldValue,
        int $limit = self::MAX_PAGE_SIZE,
        string $with = ''
    ): array {
        if (!is_scalar($fieldValue)) {
            throw new InvalidArgumentException(
                'Значение поля должно быть числом, строкой или логическим значением.',
            );
        }

        if (is_bool($fieldValue)) {
            $fieldValue = $fieldValue ? '1' : '0';
        }

        $query = "filter[custom_fields_values][$fieldId][0]=" . urlencode(trim((string) $fieldValue));
        $with = trim($with);

        if ($with !== '') {
            $query .= '&with=' . $with;
        }

        return $this->find($query, $limit);
    }

    /**
     * Выполнить полнотекстовый поиск по полям сущности.
     *
     * Пример: findByQuery('Ромашка', 25)
     */
    public function findByQuery(
        string $query,
        int $limit = self::MAX_PAGE_SIZE,
        string $with = ''
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
        string $with = ''
    ): array {
        // amoCRM ищет по подстроке цифр, поэтому номер к формату не приводится:
        // остаются только цифры, из них — последние десять. Так одинаково
        // ищутся '+7 (999) 000-00-00' и '8 999 000 00 00'. Цифр меньше десяти —
        // берутся все; нет совсем — поиск пустой, и контакты не ищутся.
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $this->findBySearchString(
            substr($digits, -self::PHONE_SEARCH_DIGITS),
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

        return $this->find($query, $limit);
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
            // В PHP 7.4 substr() от позиции за концом строки отдаёт false, а не
            // пустую строку: так бывает, когда `?` стоит последним символом.
            $query = (string) substr($query, $questionMarkPosition + 1);
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
}
