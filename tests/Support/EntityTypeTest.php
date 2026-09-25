<?php

declare(strict_types=1);

namespace Amocrm\Tests\Support;

use Amocrm\Support\EntityType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Проверка типа сущности: только `contacts`, `leads` и `companies`, строго. */
final class EntityTypeTest extends TestCase
{
    /** @dataProvider supportedTypes */
    public function testAcceptsSupportedType(string $entityType): void
    {
        self::assertSame($entityType, EntityType::validate($entityType));
    }

    public function supportedTypes(): array
    {
        return [
            'contacts' => [EntityType::CONTACT],
            'leads' => [EntityType::LEAD],
            'companies' => [EntityType::COMPANY],
        ];
    }

    /** @dataProvider unsupportedTypes */
    public function testRejectsUnsupportedType(string $entityType): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntityType::validate($entityType);
    }

    public function unsupportedTypes(): array
    {
        return [
            'единственное число' => ['lead'],
            'другой регистр' => ['Leads'],
            'с пробелом' => ['leads '],
            'элементы списков' => ['catalog_elements'],
            'пустая строка' => [''],
        ];
    }
}
