<?php

declare(strict_types=1);

namespace Amocrm\Repository;

/** Репозиторий компаний amoCRM. */
final class Company extends AbstractSearchableRepository
{
    protected function endpoint(): string
    {
        return 'api/v4/companies';
    }

    protected function embeddedKey(): string
    {
        return 'companies';
    }
}
