<?php

declare(strict_types=1);

namespace Amocrm\Repository;

/**
 * Репозиторий сделок amoCRM (сущность lead в API).
 *
 * Сделки связанного контакта или компании берут у репозитория связей:
 * links()->findLinks('contacts', $contactId) и
 * links()->findActiveLeads('contacts', $contactId).
 */
final class Lead extends AbstractSearchableRepository
{
    /** Статусы «успешно реализовано» и «закрыто и не реализовано». */
    public const DEFAULT_CLOSED_STATUS_IDS = [142, 143];

    protected function endpoint(): string
    {
        return 'api/v4/leads';
    }

    protected function embeddedKey(): string
    {
        return 'leads';
    }
}
