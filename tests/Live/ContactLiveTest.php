<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

final class ContactLiveTest extends LiveTestCase
{
    public function testFindByIdReadsCreatedContact(): void
    {
        $contact = $this->amocrm()->contacts()->findById($this->contactId());

        self::assertStringContainsString(self::runToken(), $contact['name'] ?? '');
        self::assertSame(self::runPhone(), $contact['custom_fields_values'][0]['values'][0]['value'] ?? null);
    }

    public function testUpdateChangesName(): void
    {
        $name = self::MARK . ' Контакт ' . self::runToken() . ' изменён';

        $this->amocrm()->contacts()->update([['id' => $this->contactId(), 'name' => $name]]);

        self::assertSame($name, $this->amocrm()->contacts()->findById($this->contactId())['name'] ?? null);
    }

    public function testFindByPhoneFindsContactInAnyFormat(): void
    {
        $digits = substr(self::runPhone(), 2);
        $formatted = '8 (' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-'
            . substr($digits, 6, 2) . '-' . substr($digits, 8, 2);

        $this->contactId();
        $contacts = self::eventually(fn () => $this->amocrm()->contacts()->findByPhone($formatted));

        self::assertContains($this->contactId(), array_column($contacts, 'id'), "Поиск по $formatted");
    }

    public function testFindByQueryFindsContactByName(): void
    {
        $this->contactId();
        $contacts = self::eventually(fn () => $this->amocrm()->contacts()->findByQuery(self::runToken()));

        self::assertContains($this->contactId(), array_column($contacts, 'id'));
    }

    public function testFindByIdsReadsContact(): void
    {
        $contacts = $this->amocrm()->contacts()->findByIds([$this->contactId(), 999999999]);

        self::assertSame([$this->contactId()], array_column($contacts, 'id'));
    }
}
