<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

final class CompanyLiveTest extends LiveTestCase
{
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

    public function testFindByQueryFindsCompanyByName(): void
    {
        $this->companyId();
        $companies = self::eventually(fn () => $this->amocrm()->companies()->findByQuery(self::runToken()));

        self::assertContains($this->companyId(), array_column($companies, 'id'));
    }

    public function testFindByIdReturnsNullForMissingCompany(): void
    {
        self::assertNull($this->amocrm()->companies()->findById(999999999));
    }
}
