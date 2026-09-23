<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

final class TaskLiveTest extends LiveTestCase
{
    public function testTaskLifecycle(): void
    {
        $tasks = $this->amocrm()->tasks();

        $task = $tasks->createForEntity('leads', $this->leadId(), [
            'text' => self::MARK . ' задача',
            'complete_till' => time() + 86400,
        ]);
        self::assertIsInt($task['id'] ?? null);

        try {
            $open = $tasks->findForEntity('leads', $this->leadId(), 'filter[is_completed]=0');
            self::assertContains($task['id'], array_column($open, 'id'));
        } finally {
            // Закрыть в любом случае, чтобы задача не висела в списке у людей.
            $tasks->update([['id' => $task['id'], 'is_completed' => true, 'result' => ['text' => self::MARK]]]);
        }

        self::assertTrue($tasks->findById($task['id'])['is_completed'] ?? null);
    }
}
