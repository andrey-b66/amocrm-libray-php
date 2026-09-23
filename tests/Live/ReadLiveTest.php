<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/** Чтение справочных данных аккаунта: ничего не создаёт. */
final class ReadLiveTest extends LiveTestCase
{
    public function testPipelinesIncludeTestPipeline(): void
    {
        $pipelines = $this->amocrm()->pipelines()->find('', 250, null);

        self::assertContains(self::pipelineId(), array_column($pipelines, 'id'));
    }

    public function testActiveUsersExist(): void
    {
        self::assertNotSame([], $this->amocrm()->users()->getActive());
    }

    public function testFindByIdReturnsNullForMissingUser(): void
    {
        // В отличие от сделок, на несуществующего пользователя amoCRM отвечает 404.
        self::assertNull($this->amocrm()->users()->findById(999999999));
    }
}
