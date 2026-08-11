<?php

namespace Amocrm\Support;

class FormattedNumberPhone
{
    public static function getLastTenDigits(string $numberPhone): string
    {
        // 1. Убираем всё, кроме цифр
        $digitsOnly = preg_replace('/[^0-9]/', '', $numberPhone);

        // 2. Если цифр меньше 10 — возвращаем как есть (или можно вернуть пустую строку/ошибку)
        if (strlen($digitsOnly) < 10) {
            return $digitsOnly; // или return '';
        }

        // 3. Берём последние 10 символов
        return substr($digitsOnly, -10);
    }

    public static function russianStandart(string $numberPhone): string
    {
        // Удаляем все кроме цифр и знака +
        $originalPhone = $numberPhone;
        $numberPhone = preg_replace('/[^0-9+]/', '', $numberPhone);

        // Случай 1: +79... (уже правильный формат с плюсом)
        if (strpos($numberPhone, '+79') === 0) {
            return '7' . substr($numberPhone, 2); // убираем +7, оставляем 7
        }

        // Случай 2: +78... (Санкт-Петербург)
        if (strpos($numberPhone, '+78') === 0) {
            return '7' . substr($numberPhone, 2); // заменяем +8 на 7
        }

        // Случай 3: 89... (начинается с 8 и дальше 9)
        if (strpos($numberPhone, '89') === 0) {
            return '7' . substr($numberPhone, 1); // заменяем 8 на 7
        }

        // Случай 4: 88... (начинается с 8 и дальше 8 - Санкт-Петербург)
        if (strpos($numberPhone, '88') === 0) {
            return '7' . substr($numberPhone, 1); // заменяем 8 на 7
        }

        // Случай 5: 79... (уже правильный формат без плюса)
        if (strpos($numberPhone, '79') === 0 && strlen($numberPhone) === 11) {
            return $numberPhone;
        }

        // Случай 6: 78... (Санкт-Петербург без плюса)
        if (strpos($numberPhone, '78') === 0 && strlen($numberPhone) === 11) {
            return $numberPhone; // оставляем как есть, это валидный российский номер
        }

        // Случай 7: 10-значный номер (без кода страны)
        if (strlen($numberPhone) === 10) {
            // Проверяем, что первая цифра 9 (для мобильных) или 8 (для городских)
            $firstDigit = $numberPhone[0];
            if (in_array($firstDigit, ['9', '8'])) {
                return '7' . $numberPhone;
            }
        }

        // Если ничего не подошло, возвращаем исходную строку
        return $originalPhone;
    }
}
