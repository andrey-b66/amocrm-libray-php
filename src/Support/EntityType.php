<?php

declare(strict_types=1);

namespace Amocrm\Support;

use InvalidArgumentException;

/** Поддерживаемые типы сущностей для примечаний, задач и связей. */
final class EntityType
{
    public const CONTACT = 'contacts';
    public const LEAD = 'leads';
    public const COMPANY = 'companies';

    private const SUPPORTED = [
        self::CONTACT,
        self::LEAD,
        self::COMPANY,
    ];

    public static function validate(string $entityType): string
    {
        if (!in_array($entityType, self::SUPPORTED, true)) {
            throw new InvalidArgumentException(
                'Поддерживаются только типы сущностей `contacts`, `leads` и `companies`.',
            );
        }

        return $entityType;
    }

    private function __construct()
    {
    }
}
