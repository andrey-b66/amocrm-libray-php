<?php

declare(strict_types=1);

namespace Amocrm\Repository;

use Amocrm\Support\ApiReader;
use InvalidArgumentException;

/**
 * Репозиторий сущностей с поиском: контакты, компании и сделки.
 *
 * Полнотекстовый `query` и фильтр по пользовательским полям amoCRM понимает
 * только у них. Незнакомый параметр она не отклоняет, а молча отдаёт всю
 * выборку, поэтому у задач и воронок поиска нет вовсе: иначе он вернул бы
 * не найденное, а первые попавшиеся записи.
 */
abstract class AbstractSearchableRepository extends AbstractApiRepository
{
    /** Сколько последних цифр номера amoCRM использует при поиске по телефону. */
    private const PHONE_SEARCH_DIGITS = 10;

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
        int $limit = ApiReader::MAX_PAGE_SIZE,
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

        return $this->find(self::appendWith($query, $with), $limit);
    }

    /**
     * Выполнить полнотекстовый поиск по полям сущности.
     *
     * Пример: findByQuery('Ромашка', 25)
     */
    public function findByQuery(
        string $query,
        int $limit = ApiReader::MAX_PAGE_SIZE,
        string $with = ''
    ): array {
        return $this->findBySearchString(trim($query), $limit, $with);
    }

    /**
     * Выполнить полнотекстовый поиск по последним 10 цифрам номера телефона.
     * Цифр меньше десяти — берутся все; нет совсем — поиск пустой, и запрос не отправляется.
     *
     * Пример: findByPhone('+7 (999) 000-00-00')
     */
    public function findByPhone(
        string $phone,
        int $limit = ApiReader::MAX_PAGE_SIZE,
        string $with = ''
    ): array {
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

        return $this->find(self::appendWith('query=' . urlencode($search), $with), $limit);
    }
}
