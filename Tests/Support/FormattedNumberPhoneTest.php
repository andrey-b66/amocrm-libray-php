<?php

declare(strict_types=1);

namespace Amocrm\Tests\Support;

use Amocrm\Support\FormattedNumberPhone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FormattedNumberPhoneTest extends TestCase
{
    public function testGetLastDigits(): void
    {
        // По умолчанию берём 10 последних цифр — столько использует поиск amoCRM.
        self::assertSame(10, FormattedNumberPhone::SEARCH_DIGITS);
        self::assertSame('9990000000', FormattedNumberPhone::getLastDigits('+7 (999) 000-00-00'));
        self::assertSame('9990000000', FormattedNumberPhone::getLastDigits('79990000000'));

        // Скобки, дефисы, пробелы и плюс отбрасываются, остаются только цифры.
        self::assertSame('5291234567', FormattedNumberPhone::getLastDigits('+375 (29) 123-45-67'));

        // Добавочный номер тоже состоит из цифр и попадает в результат.
        self::assertSame('0000000123', FormattedNumberPhone::getLastDigits('+7 999 000-00-00 доб. 123'));

        // Цифр меньше, чем просят, — возвращаем всё, что есть.
        self::assertSame('12345', FormattedNumberPhone::getLastDigits('12345'));
        self::assertSame('1234567890', FormattedNumberPhone::getLastDigits('1234567890'));

        // Цифр нет вообще — пустая строка, а не ошибка.
        self::assertSame('', FormattedNumberPhone::getLastDigits('нет цифр'));
        self::assertSame('', FormattedNumberPhone::getLastDigits(''));
    }

    public function testGetLastDigitsWithCustomCount(): void
    {
        // Сколько цифр нужно — решает вызывающий код.
        self::assertSame('0000000', FormattedNumberPhone::getLastDigits('+7 (999) 000-00-00', 7));
        self::assertSame('1', FormattedNumberPhone::getLastDigits('+7 (999) 000-00-01', 1));
        self::assertSame('375291234567', FormattedNumberPhone::getLastDigits('+375 (29) 123-45-67', 12));
    }

    public function testSameNumberInAnyFormatGivesSameResult(): void
    {
        // Главное свойство: как бы менеджер ни записал номер, ключ поиска один и тот же.
        $expected = '9990000000';

        self::assertSame($expected, FormattedNumberPhone::getLastDigits('+7 (999) 000-00-00'));
        self::assertSame($expected, FormattedNumberPhone::getLastDigits('8 999 000 00 00'));
        self::assertSame($expected, FormattedNumberPhone::getLastDigits('79990000000'));
        self::assertSame($expected, FormattedNumberPhone::getLastDigits('тел.: +7_999_000.00.00'));
    }

    public function testGetLastDigitsRejectsZeroCount(): void
    {
        // Ноль цифр запросить нельзя: ждём исключение вместо молчаливой ерунды.
        $this->expectException(InvalidArgumentException::class);

        FormattedNumberPhone::getLastDigits('+7 999 000-00-00', 0);
    }

    public function testGetLastDigitsRejectsNegativeCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FormattedNumberPhone::getLastDigits('+7 999 000-00-00', -5);
    }
}
