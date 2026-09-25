<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/**
 * Теги на живом аккаунте. Удалить тег из справочника через API нельзя, поэтому
 * тесты обходятся одним тегом `[autotest]` — дубли amoCRM не заводит.
 */
final class TagLiveTest extends LiveTestCase
{
    public function testLeadTagLifecycle(): void
    {
        $tags = $this->amocrm()->tags();

        $tags->addToEntity('leads', $this->leadId(), [self::MARK]);
        self::assertContains(self::MARK, $this->tagNames('leads'), 'Тег добавляется по названию.');

        $tagId = null;

        foreach ($tags->find('leads', 'query=' . self::MARK) as $tag) {
            if ($tag['name'] === self::MARK) {
                $tagId = $tag['id'];
            }
        }

        self::assertIsInt($tagId, 'Тег есть в справочнике.');

        $tags->removeFromEntity('leads', $this->leadId(), [$tagId]);
        self::assertSame([], $this->tagNames('leads'), 'Тег снимается по ID.');

        $tags->addToEntity('leads', $this->leadId(), [$tagId]);
        $tags->clearForEntity('leads', $this->leadId());
        self::assertSame([], $this->tagNames('leads'), 'clearForEntity снимает все теги.');
    }

    public function testCreateReturnsExistingTagInsteadOfDuplicate(): void
    {
        $tags = $this->amocrm()->tags();

        [$first] = $tags->create('leads', [['name' => self::MARK]]);
        [$second] = $tags->create('leads', [['name' => self::MARK]]);

        self::assertIsInt($first['id'] ?? null);
        self::assertSame($first['id'], $second['id'] ?? null);
        self::assertSame(
            [$first['id']],
            array_column($tags->find('leads', 'filter[name]=' . self::MARK), 'id'),
            'В справочнике один тег с таким названием.',
        );
    }

    public function testContactTags(): void
    {
        $tags = $this->amocrm()->tags();

        $tags->addToEntity('contacts', $this->contactId(), [self::MARK]);
        self::assertContains(self::MARK, $this->tagNames('contacts'));

        $tags->clearForEntity('contacts', $this->contactId());
        self::assertSame([], $this->tagNames('contacts'));
    }

    /** Названия тегов сделки или контакта этого прогона, прочитанные заново. */
    private function tagNames(string $entityType): array
    {
        $entity = $entityType === 'leads'
            ? $this->amocrm()->leads()->findById($this->leadId())
            : $this->amocrm()->contacts()->findById($this->contactId());

        return array_column($entity['_embedded']['tags'] ?? [], 'name');
    }
}
