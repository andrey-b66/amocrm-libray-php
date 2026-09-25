<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/** Воронки на живом аккаунте: только чтение, ничего не создаёт. */
final class PipelineLiveTest extends LiveTestCase
{
    public function testFindAllIncludesTestPipeline(): void
    {
        self::assertContains(self::pipelineId(), array_column($this->amocrm()->pipelines()->findAll(), 'id'));
    }

    public function testFindByIdReadsPipelineWithStatuses(): void
    {
        $pipeline = $this->amocrm()->pipelines()->findById(self::pipelineId());

        self::assertSame(self::pipelineId(), $pipeline['id'] ?? null);
        self::assertContains(self::statusId(), array_column($pipeline['_embedded']['statuses'] ?? [], 'id'));
    }

    public function testFindByIdReturnsNullForMissingPipeline(): void
    {
        // На несуществующую воронку amoCRM отвечает 404.
        self::assertNull($this->amocrm()->pipelines()->findById(999999999));
    }
}
