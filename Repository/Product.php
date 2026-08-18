<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Exception\ApiException;

/**
 * Репозиторий товаров amoCRM.
 *
 * Товары — элементы служебного списка с типом `products`, поэтому репозиторий
 * умеет всё то же, что CatalogElement, но ID списка находит сам. Берут его у
 * репозитория списков: Catalog::products().
 *
 * Цена, артикул и остаток лежат в `custom_fields_values` как обычные поля:
 * их ID берут из `api/v4/catalogs/{catalog_id}/custom_fields`.
 *
 * Пример: $products = $amocrm->catalogs()->products();
 *         $product = $products->create(['name' => 'Стул']);
 *         $products->linkToLead($leadId, $product['id'], 2);
 */
final class Product extends CatalogElement
{
    /**
     * Получить ID списка товаров.
     *
     * Список заводит сама amoCRM, поэтому он ищется по типу и запоминается:
     * иначе каждый запрос к товарам начинался бы с обхода списков.
     */
    public function catalogId(): int
    {
        return $this->catalogId ??= $this->findProductsCatalogId();
    }

    private function findProductsCatalogId(): int
    {
        $catalogId = (new Catalog($this->request))->findProducts()['id'] ?? null;

        if (!is_int($catalogId)) {
            throw new ApiException(
                'В аккаунте amoCRM нет списка товаров: модуль товаров выключен или нет прав на него.',
                404,
                'GET',
                'api/v4/catalogs',
            );
        }

        return $catalogId;
    }
}
