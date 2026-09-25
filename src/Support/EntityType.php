<?php

declare(strict_types=1);

namespace Amocrm\Support;

use InvalidArgumentException;

/** Типы сущностей для примечаний, задач, тегов и связей — в том виде, как в пути API. */
final class EntityType
{
    public const CONTACT = 'contacts';
    public const LEAD = 'leads';
    public const COMPANY = 'companies';

    private const SUPPORTED = [self::CONTACT, self::LEAD, self::COMPANY];

    /** Вернуть тип как есть, если он поддерживается; иначе InvalidArgumentException. */
    public static function validate(string $entityType): string
    {
        if (!in_array($entityType, self::SUPPORTED, true)) {
            throw new InvalidArgumentException(
                'Поддерживаются только типы сущностей `contacts`, `leads` и `companies`.',
            );
        }

        return $entityType;
    }

    /** Экземпляры не нужны: у класса только константы и статический метод. */
    private function __construct()
    {
    }
}
