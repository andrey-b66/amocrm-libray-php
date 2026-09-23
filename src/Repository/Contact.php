<?php

declare(strict_types=1);

namespace Amocrm\Repository;

/** Репозиторий контактов amoCRM. */
final class Contact extends AbstractSearchableRepository
{
    protected function endpoint(): string
    {
        return 'api/v4/contacts';
    }

    protected function embeddedKey(): string
    {
        return 'contacts';
    }
}
