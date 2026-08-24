<?php

declare(strict_types=1);

namespace Amocrm\Repository;

/**
 * Репозиторий списков amoCRM.
 *
 * Список — справочник со своими полями: товары, счета или произвольный
 * пользовательский список. Сами записи справочника лежат в отдельных
 * репозиториях: elements($catalogId) для любого списка, products() для товаров.
 *
 * Поиска и фильтров по спискам в amoCRM нет, есть только постраничная выдача,
 * поэтому нужный список ищут в результате findAll(): create(), update(),
 * findById(), findAll() и find() работают, поисковые методы — нет.
 *
 * Пример: $catalogs = $amocrm->catalogs()->findAll();
 *         $products = $amocrm->catalogs()->products()->findAll();
 *         $elements = $amocrm->catalogs()->elements($catalogId)->findAll();
 */
final class Catalog extends AbstractApiRepository
{
    /** Служебный список товаров. */
    public const TYPE_PRODUCTS = 'products';

    /** Служебный список счетов. */
    public const TYPE_INVOICES = 'invoices';

    /** Служебный список юридических лиц аккаунта. */
    public const TYPE_SUPPLIERS = 'suppliers';

    /** Обычный пользовательский список. */
    public const TYPE_REGULAR = 'regular';

    private ?Product $products = null;

    /** Получить репозиторий элементов конкретного списка. */
    public function elements(int $catalogId): CatalogElement
    {
        return new CatalogElement($this->request, $catalogId);
    }

    /**
     * Получить репозиторий товаров.
     *
     * Список товаров репозиторий находит сам и запоминает, поэтому возвращается
     * один и тот же — иначе обход списков повторялся бы на каждый вызов. Если
     * списков товаров в аккаунте несколько, ID нужного передают аргументом.
     *
     * Пример: products()->findByQuery('Стул')
     */
    public function products(?int $catalogId = null): Product
    {
        if ($catalogId !== null) {
            return new Product($this->request, $catalogId);
        }

        return $this->products ??= new Product($this->request);
    }

    /**
     * Найти списки по типу: `regular`, `products`, `invoices` или `suppliers`.
     *
     * Пример: findByType(Catalog::TYPE_REGULAR)
     */
    public function findByType(string $type): array
    {
        return array_values(array_filter(
            $this->findAll(),
            static fn (array $catalog): bool => ($catalog['type'] ?? null) === $type,
        ));
    }

    /**
     * Найти служебный список товаров.
     *
     * Возвращает null, если товары в аккаунте не включены.
     */
    public function findProducts(): ?array
    {
        return $this->findByType(self::TYPE_PRODUCTS)[0] ?? null;
    }

    /**
     * Получить списки по ID в порядке переданных ID.
     *
     * Фильтр по ID amoCRM для списков не поддерживает, поэтому нужные выбираются
     * из полной выдачи — списков в аккаунте немного. Связанных сущностей у
     * списка нет, поэтому `$with` остаётся без дела: он только ради общей
     * сигнатуры с остальными репозиториями.
     */
    public function findByIds(array $ids, string $with = ''): array
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return [];
        }

        $catalogsById = [];

        foreach ($this->findAll() as $catalog) {
            $catalogId = $catalog['id'] ?? null;

            if (is_int($catalogId)) {
                $catalogsById[$catalogId] = $catalog;
            }
        }

        $result = [];

        foreach ($ids as $id) {
            if (isset($catalogsById[$id])) {
                $result[] = $catalogsById[$id];
            }
        }

        return $result;
    }

    protected function endpoint(): string
    {
        return 'api/v4/catalogs';
    }

    protected function embeddedKey(): string
    {
        return 'catalogs';
    }
}
