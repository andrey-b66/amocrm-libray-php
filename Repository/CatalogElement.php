<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Client\ApiClient;
use BadMethodCallException;

/**
 * Репозиторий элементов одного списка amoCRM.
 *
 * Элемент — это запись справочника: товар, счёт или строка пользовательского
 * списка. Репозиторий привязан к одному списку, его ID передаётся в конструктор
 * или берётся у Catalog::elements(). Товары наследуют этот репозиторий и находят
 * свой список сами.
 *
 * Из поиска amoCRM даёт по элементам только фильтр по ID и полнотекстовый
 * `query`: find(), findAll(), findById(), findByIds() и findByQuery() работают,
 * а findByField() — нет, фильтр по значению поля amoCRM для списков не делает.
 *
 * Пример: $elements = $amocrm->catalogs()->elements($catalogId);
 *         $elements->create(['name' => 'Стул', 'custom_fields_values' => [...]]);
 */
class CatalogElement extends AbstractApiRepository
{
    /** Тип связанной сущности, под которым элементы списков ходят в связях. */
    protected const LINK_ENTITY_TYPE = 'catalog_elements';

    protected ?int $catalogId;

    public function __construct(ApiClient $apiClient, ?int $catalogId = null)
    {
        parent::__construct($apiClient);

        $this->catalogId = $catalogId;
    }

    /** Получить ID списка, с элементами которого работает репозиторий. */
    public function catalogId(): int
    {
        if ($this->catalogId === null) {
            throw new BadMethodCallException('Не указан ID списка amoCRM.');
        }

        return $this->catalogId;
    }

    /**
     * Привязать элемент к сделке или задать ему новое количество.
     *
     * Второй раз тот же элемент в сделке не появится: повторная привязка
     * перезаписывает количество, а не добавляет строку и не суммирует. Так что
     * менять количество — это тоже вызвать этот метод.
     *
     * Количество бывает и дробным — например, 2.5 килограмма.
     *
     * `$priceId` нужен, только когда у списка несколько полей типа «Цена»: он
     * указывает, из какого поля брать цену в сделке.
     *
     * Пример: linkToLead($leadId, $productId, 2)
     */
    public function linkToLead(
        int $leadId,
        int $elementId,
        int|float $quantity = 1,
        ?int $priceId = null,
    ): array {
        $metadata = [
            'catalog_id' => $this->catalogId(),
            'quantity' => $quantity,
        ];

        if ($priceId !== null) {
            $metadata['price_id'] = $priceId;
        }

        $response = $this->request->post("api/v4/leads/$leadId/link", [[
            'to_entity_id' => $elementId,
            'to_entity_type' => static::LINK_ENTITY_TYPE,
            'metadata' => $metadata,
        ]]);

        return $response['_embedded']['links'][0] ?? [];
    }

    /** Отвязать элемент от сделки. */
    public function unlinkFromLead(int $leadId, int $elementId): bool
    {
        $this->request->post("api/v4/leads/$leadId/unlink", [[
            'to_entity_id' => $elementId,
            'to_entity_type' => static::LINK_ENTITY_TYPE,
            'metadata' => ['catalog_id' => $this->catalogId()],
        ]]);

        return true;
    }

    /**
     * Получить связи сделки с элементами этого списка — с количеством в `metadata`.
     *
     * Возвращаются пары ID, а не сами элементы: количество и ID поля цены лежат
     * в `metadata`, данные элемента — в findForLead(). Количество приходит
     * дробным числом даже для целых штук: `quantity` равно 2.0, а не 2.
     *
     * Пример: findLinksForLead($leadId)
     */
    public function findLinksForLead(int $leadId): array
    {
        $response = $this->request->get(
            "api/v4/leads/$leadId/links",
            'filter[to_entity_type]=' . static::LINK_ENTITY_TYPE
            . '&filter[to_catalog_id]=' . $this->catalogId(),
        );

        return array_values($response['_embedded']['links'] ?? []);
    }

    /**
     * Получить привязанные к сделке элементы этого списка целиком.
     *
     * Дубликатов не будет: одному элементу в сделке отвечает одна связь, сколько
     * бы штук в ней ни было. Количество берут из findLinksForLead().
     *
     * Пример: findForLead($leadId)
     */
    public function findForLead(int $leadId): array
    {
        $elementIds = [];

        foreach ($this->findLinksForLead($leadId) as $link) {
            $elementId = $link['to_entity_id'] ?? null;

            if (is_int($elementId)) {
                $elementIds[$elementId] = $elementId;
            }
        }

        return $this->findByIds(array_values($elementIds));
    }

    protected function endpoint(): string
    {
        return 'api/v4/catalogs/' . $this->catalogId() . '/elements';
    }

    protected function embeddedKey(): string
    {
        return 'elements';
    }

    /**
     * Поиска по значению поля у элементов списков нет.
     *
     * Фильтр `filter[custom_fields_values]` amoCRM для них не выполняет и не
     * отклоняет: она молча отдаёт весь список — даже на значение, которого в
     * списке заведомо нет. Тихо вернуть не те данные хуже, чем сказать об этом,
     * поэтому метод и не работает. Ближайшая замена — findByQuery().
     */
    public function findByField(
        int $fieldId,
        int|float|string|bool $fieldValue,
        int $limit = self::MAX_PAGE_SIZE,
        string $with = '',
    ): array {
        throw new BadMethodCallException(
            'amoCRM не фильтрует элементы списков по значению поля: фильтр игнорируется, '
            . 'а в ответ приходит весь список. Используйте findByQuery().',
        );
    }
}
