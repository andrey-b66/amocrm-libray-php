<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

final class NoteLiveTest extends LiveTestCase
{
    public function testCommonNoteLifecycle(): void
    {
        $notes = $this->amocrm()->notes();

        $note = $notes->createCommon('leads', $this->leadId(), '  ' . self::MARK . ' примечание  ');
        self::assertIsInt($note['id'] ?? null);

        $found = $notes->findById('leads', $this->leadId(), $note['id']);
        self::assertSame(self::MARK . ' примечание', $found['params']['text'] ?? null, 'Текст обрезается.');

        $notes->update('leads', $this->leadId(), $note['id'], [
            'note_type' => 'common',
            'params' => ['text' => self::MARK . ' изменено'],
        ]);
        $found = $notes->findById('leads', $this->leadId(), $note['id']);
        self::assertSame(self::MARK . ' изменено', $found['params']['text'] ?? null);

        $list = $notes->findForEntity('leads', $this->leadId(), 'filter[note_type][0]=common', 250, null);
        self::assertContains($note['id'], array_column($list, 'id'));
    }

    public function testFindByIdReturnsNullForMissingNote(): void
    {
        // amoCRM отвечает на несуществующее примечание 204 без тела.
        self::assertNull($this->amocrm()->notes()->findById('leads', $this->leadId(), 999999999));
    }
}
