<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

final class TagLiveTest extends LiveTestCase
{
    public function testTagLifecycle(): void
    {
        $tags = $this->amocrm()->tags();

        $tags->addToEntity('leads', $this->leadId(), [self::MARK]);
        self::assertContains(self::MARK, $this->leadTagNames(), 'Тег добавляется по названию.');

        $tagId = null;

        foreach ($tags->find('leads', 'query=' . self::MARK) as $tag) {
            if ($tag['name'] === self::MARK) {
                $tagId = $tag['id'];
            }
        }

        self::assertIsInt($tagId, 'Тег есть в справочнике.');

        $tags->removeFromEntity('leads', $this->leadId(), [$tagId]);
        self::assertSame([], $this->leadTagNames(), 'Тег снимается по ID.');

        $tags->addToEntity('leads', $this->leadId(), [$tagId]);
        $tags->clearForEntity('leads', $this->leadId());
        self::assertSame([], $this->leadTagNames(), 'clearForEntity снимает все теги.');
    }

    private function leadTagNames(): array
    {
        $lead = $this->amocrm()->leads()->findById($this->leadId());

        return array_column($lead['_embedded']['tags'] ?? [], 'name');
    }
}
