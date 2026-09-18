<?php

declare(strict_types=1);

namespace Amocrm\Repository;

/** Репозиторий воронок сделок amoCRM. */
final class Pipeline extends AbstractApiRepository
{
    protected function endpoint(): string
    {
        return 'api/v4/leads/pipelines';
    }

    protected function embeddedKey(): string
    {
        return 'pipelines';
    }
}
