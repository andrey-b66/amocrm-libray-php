<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

use Amocrm\Exception\ApiException;

/** Контакты на живом аккаунте: чтение, обновление, поля и поиск. */
final class ContactLiveTest extends LiveTestCase
{
    public function testCreateReturnsIdAndRequestId(): void
    {
        $contact = $this->createdContact();

        self::assertIsInt($contact['id'] ?? null);
        self::assertSame('autotest-contact', $contact['request_id'] ?? null);
    }

    public function testFindByIdReadsCreatedContact(): void
    {
        $contact = $this->amocrm()->contacts()->findById($this->contactId());

        self::assertStringContainsString(self::runToken(), $contact['name'] ?? '');
        self::assertSame([self::runPhone()], self::fieldValues($contact, 'PHONE'));
    }

    public function testUpdateChangesName(): void
    {
        $name = self::MARK . ' Контакт ' . self::runToken() . ' изменён';

        $this->amocrm()->contacts()->update([['id' => $this->contactId(), 'name' => $name]]);

        self::assertSame($name, $this->amocrm()->contacts()->findById($this->contactId())['name'] ?? null);
    }

    /** Системные поля есть в любом аккаунте, поэтому пишутся по коду, без ID. */
    public function testUpdateWritesCustomFieldsAndKeepsOthers(): void
    {
        $position = self::MARK . ' должность ' . self::runToken();
        $email = 'autotest-' . self::runToken() . '@example.com';

        $this->amocrm()->contacts()->update([[
            'id' => $this->contactId(),
            'custom_fields_values' => [
                ['field_code' => 'POSITION', 'values' => [['value' => $position]]],
                ['field_code' => 'EMAIL', 'values' => [['value' => $email, 'enum_code' => 'WORK']]],
            ],
        ]]);

        $contact = $this->amocrm()->contacts()->findById($this->contactId());
        self::assertSame([$position], self::fieldValues($contact, 'POSITION'));
        self::assertSame([$email], self::fieldValues($contact, 'EMAIL'));
        self::assertSame([self::runPhone()], self::fieldValues($contact, 'PHONE'), 'Поля вне обновления не меняются.');
    }

    public function testFindByFieldFindsContactByFieldValue(): void
    {
        $position = self::MARK . ' поиск ' . self::runToken();

        $this->amocrm()->contacts()->update([[
            'id' => $this->contactId(),
            'custom_fields_values' => [['field_code' => 'POSITION', 'values' => [['value' => $position]]]],
        ]]);

        $fieldId = self::fieldId($this->amocrm()->contacts()->findById($this->contactId()), 'POSITION');

        try {
            $contacts = self::eventually(fn () => $this->amocrm()->contacts()->findByField($fieldId, $position));
        } catch (ApiException $exception) {
            if (($exception->getResponseData()['detail'] ?? '') === 'Invalid filter for current account') {
                self::markTestSkipped('В аккаунте не подключена API-фильтрация: «Настройки» → «Счета и оплата».');
            }

            throw $exception;
        }

        self::assertSame([$this->contactId()], array_column($contacts, 'id'));
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

    public function testFindByQueryAcceptsCyrillic(): void
    {
        $this->contactId();
        $contacts = self::eventually(
            fn () => $this->amocrm()->contacts()->findByQuery('Контакт ' . self::runToken()),
        );

        self::assertContains($this->contactId(), array_column($contacts, 'id'));
    }

    public function testFindByIdsReadsContact(): void
    {
        $contacts = $this->amocrm()->contacts()->findByIds([$this->contactId(), 999999999]);

        self::assertSame([$this->contactId()], array_column($contacts, 'id'));
    }
}
