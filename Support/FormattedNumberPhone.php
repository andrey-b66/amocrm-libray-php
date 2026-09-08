<?php

declare(strict_types=1);

namespace Amocrm\Support;

use InvalidArgumentException;

/**
 * Подготовка телефонного номера к поиску в amoCRM.
 *
 * amoCRM ищет по подстроке цифр, поэтому нормализовать номер к какому-либо
 * формату не нужно — достаточно оставить цифры и взять последние из них.
 */
final class FormattedNumberPhone
{
    /** Сколько последних цифр номера amoCRM использует при поиске по телефону. */
    public const SEARCH_DIGITS = 10;

    /**
     * Последние $count цифр номера — по ним amoCRM ищет контакты.
     *
     * Примеры:
     *   getLastDigits('+7 (999) 000-00-00')    → '9990000000'
     *   getLastDigits('+7 (999) 000-00-00', 7) → '0000000'
     *
     * Если цифр меньше запрошенного, возвращает те, что есть; для строки без цифр — пустую.
     */
    public static function getLastDigits(string $numberPhone, int $count = self::SEARCH_DIGITS): string
    {
        if ($count < 1) {
            throw new InvalidArgumentException(
                "Количество цифр должно быть положительным, получено: {$count}.",
            );
        }

        $digits = self::onlyDigits($numberPhone);

        // Цифр меньше, чем просят, — отдаём всё, что есть.
        if (strlen($digits) <= $count) {
            return $digits;
        }

        $startPosition = strlen($digits) - $count;

        return substr($digits, $startPosition);
    }

    /** Оставить в строке только цифры: '+7 (999) 000-00-00' → '79990000000'. */
    private static function onlyDigits(string $numberPhone): string
    {
        $digits = preg_replace('/\D+/', '', $numberPhone);

        // preg_replace возвращает null, если движок регулярных выражений дал сбой.
        if ($digits === null) {
            return '';
        }

        return $digits;
    }
}
