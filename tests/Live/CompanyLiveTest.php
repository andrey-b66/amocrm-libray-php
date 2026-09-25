<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/** Компании на живом аккаунте: чтение, обновление, поля и поиск. */
final class CompanyLiveTest extends LiveTestCase
{
    public function testCreateReturnsIdAndRequestId(): void
    {
        $company = $this->createdCompany();

        self::assertIsInt($company['id'] ?? null);
        self::assertSame('autotest-company', $company['request_id'] ?? null);
    }

    public function testFindByIdReadsCreatedCompany(): void
    {
        $company = $this->amocrm()->companies()->findById($this->companyId());

        self::assertStringContainsString(self::runToken(), $company['name'] ?? '');
    }

    public function testUpdateChangesName(): void
    {
        $name = self::MARK . ' Компания ' . self::runToken() . ' изменена';

        $this->amocrm()->companies()->update([['id' => $this->companyId(), 'name' => $name]]);

        self::assertSame($name, $this->amocrm()->companies()->findById($this->companyId())['name'] ?? null);
    }

    public function testUpdateWritesCustomFields(): void
    {
        $web = 'https://example.com/autotest-' . self::runToken();
        $address = self::MARK . ' Москва, Тестовая улица, ' . self::runToken();

        $this->amocrm()->companies()->update([[
            'id' => $this->companyId(),
            'custom_fields_values' => [
                ['field_code' => 'WEB', 'values' => [['value' => $web]]],
                ['field_code' => 'ADDRESS', 'values' => [['value' => $address]]],
            ],
        ]]);

        $company = $this->amocrm()->companies()->findById($this->companyId());
        self::assertSame([$web], self::fieldValues($company, 'WEB'));
        self::assertSame([$address], self::fieldValues($company, 'ADDRESS'));
    }

    public function testFindByQueryFindsCompanyByName(): void
    {
        $this->companyId();
        $companies = self::eventually(fn () => $this->amocrm()->companies()->findByQuery(self::runToken()));

        self::assertContains($this->companyId(), array_column($companies, 'id'));
    }

    public function testFindByIdsReadsCompany(): void
    {
        $companies = $this->amocrm()->companies()->findByIds([$this->companyId(), 999999999]);

        self::assertSame([$this->companyId()], array_column($companies, 'id'));
    }

    public function testFindByIdReturnsNullForMissingCompany(): void
    {
        self::assertNull($this->amocrm()->companies()->findById(999999999));
    }
}
