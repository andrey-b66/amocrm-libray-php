<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Repository\Pipeline;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;

/** Воронки только читаются: весь список или одна по ID. */
final class PipelineTest extends FakeAmocrmTestCase
{
    public function testFindAllReadsListWithoutPagination(): void
    {
        $this->respond(self::page('pipelines', [['id' => 1], ['id' => 2]]));

        self::assertSame([['id' => 1], ['id' => 2]], $this->pipelines()->findAll());
        // Страниц у воронок нет, поэтому ни page, ни limit в запросе.
        self::assertSame(['GET /api/v4/leads/pipelines'], $this->requestLog());
    }

    public function testFindAllReturnsEmptyListOnNoContent(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->pipelines()->findAll());
    }

    public function testFindById(): void
    {
        $this->respond(['id' => 7, 'name' => 'Продажи']);

        self::assertSame(['id' => 7, 'name' => 'Продажи'], $this->pipelines()->findById(7));
        self::assertSame(['GET /api/v4/leads/pipelines/7'], $this->requestLog());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        // Так amoCRM отвечает на несуществующую воронку.
        $this->respond(['title' => 'Not Found', 'detail' => 'Pipeline not found'], 404);

        self::assertNull($this->pipelines()->findById(999999999));
    }

    public function testIsReadOnly(): void
    {
        foreach (['create', 'update', 'find', 'findByIds'] as $method) {
            self::assertFalse(method_exists(Pipeline::class, $method), "Pipeline::$method()");
        }
    }

    private function pipelines(): Pipeline
    {
        return $this->amocrm()->pipelines();
    }
}
