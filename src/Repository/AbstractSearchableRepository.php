<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Support\ApiReader;
use InvalidArgumentException;

/** Репозиторий с поиском по тексту, телефону и полям: контакты, компании и сделки. */
abstract class AbstractSearchableRepository extends AbstractApiRepository
{
    /** Сколько последних цифр номера amoCRM использует при поиске по телефону. */
    private const PHONE_SEARCH_DIGITS = 10;

    /**
     * Найти сущности по точному значению пользовательского поля. Нужна подключённая
     * в аккаунте API-фильтрация, иначе amoCRM отвечает HTTP 400.
     *
     * Пример: findByField(123456, 'ООО Ромашка')
     *
     * @param int|float|string|bool $fieldValue
     */
    public function findByField(
        int $fieldId,
        $fieldValue,
        int $limit = ApiReader::MAX_PAGE_SIZE,
        string $with = ''
    ): array {
        if (!is_scalar($fieldValue)) {
            throw new InvalidArgumentException(
                'Значение поля должно быть числом, строкой или логическим значением.',
            );
        }

        // (string) false — пустая строка, поэтому логическое значение уходит как 1 или 0.
        if (is_bool($fieldValue)) {
            $fieldValue = (int) $fieldValue;
        }

        $query = "filter[custom_fields_values][$fieldId][0]=" . urlencode(trim((string) $fieldValue));

        return $this->find(ApiReader::appendWith($query, $with), $limit);
    }

    /**
     * Полнотекстовый поиск по полям сущности; пустая строка возвращает пустой список.
     *
     * Пример: findByQuery('Ромашка', 25)
     */
    public function findByQuery(
        string $query,
        int $limit = ApiReader::MAX_PAGE_SIZE,
        string $with = ''
    ): array {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        return $this->find(ApiReader::appendWith('query=' . urlencode($query), $with), $limit);
    }

    /**
     * Поиск по телефону в любом формате — по последним 10 цифрам номера.
     *
     * Пример: findByPhone('+7 (999) 000-00-00')
     */
    public function findByPhone(
        string $phone,
        int $limit = ApiReader::MAX_PAGE_SIZE,
        string $with = ''
    ): array {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $this->findByQuery(substr($digits, -self::PHONE_SEARCH_DIGITS), $limit, $with);
    }
}
