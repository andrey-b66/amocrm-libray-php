<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

use Amocrm\Exception\ApiException;

/** Сделки на живом аккаунте: создание, чтение, обновление и ошибки валидации. */
final class LeadLiveTest extends LiveTestCase
{
    public function testCreateReturnsIdAndRequestId(): void
    {
        $lead = $this->createdLead();

        self::assertIsInt($lead['id'] ?? null);
        self::assertSame('autotest', $lead['request_id'] ?? null);
    }

    public function testFindByIdReadsCreatedLead(): void
    {
        $lead = $this->amocrm()->leads()->findById($this->leadId());

        self::assertSame(self::pipelineId(), $lead['pipeline_id'] ?? null);
        self::assertSame(self::statusId(), $lead['status_id'] ?? null);
    }

    public function testUpdateChangesLead(): void
    {
        $price = random_int(1000, 9999);

        $updated = $this->amocrm()->leads()->update([['id' => $this->leadId(), 'price' => $price]]);

        self::assertSame($this->leadId(), $updated[0]['id'] ?? null);
        self::assertSame($price, $this->amocrm()->leads()->findById($this->leadId())['price'] ?? null);
    }

    public function testFindByPipelineSeesNewLeadFirst(): void
    {
        $this->createdLead();

        $leads = $this->amocrm()->leads()->find('filter[pipeline_id][0]=' . self::pipelineId() . '&order[id]=desc', 5);

        self::assertSame($this->leadId(), $leads[0]['id'] ?? null);
    }

    public function testFindByQueryFindsLeadByName(): void
    {
        $this->createdLead();
        $leads = self::eventually(fn () => $this->amocrm()->leads()->findByQuery(self::runToken()));

        self::assertContains($this->leadId(), array_column($leads, 'id'));
    }

    public function testFindByIdsDropsDuplicatesAndMissing(): void
    {
        $leads = $this->amocrm()->leads()->findByIds([$this->leadId(), $this->leadId(), 999999999]);

        self::assertCount(1, $leads);
        self::assertSame($this->leadId(), $leads[0]['id']);
    }

    public function testFindByIdReturnsNullForMissingLead(): void
    {
        // amoCRM отвечает на несуществующую сделку 204 без тела.
        self::assertNull($this->amocrm()->leads()->findById(999999999));
    }

    public function testValidationErrorComesAsApiException(): void
    {
        try {
            $this->amocrm()->leads()->update([['id' => $this->leadId(), 'price' => 'не число']]);
            self::fail('Ожидалось ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
            self::assertNotSame([], $exception->getValidationErrors());
        }
    }
}
