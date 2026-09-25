<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

/** Примечания на живом аккаунте: создание, чтение и правка. */
final class NoteLiveTest extends LiveTestCase
{
    public function testCommonNoteLifecycle(): void
    {
        $notes = $this->amocrm()->notes();

        $note = $notes->createCommon('leads', $this->leadId(), '  ' . self::MARK . ' примечание  ');
        self::assertIsInt($note['id'] ?? null);

        $found = $notes->findById('leads', $this->leadId(), $note['id']);
        self::assertSame(self::MARK . ' примечание', $found['params']['text'] ?? null, 'Текст обрезается.');

        $updated = $notes->update('leads', [[
            'id' => $note['id'],
            'entity_id' => $this->leadId(),
            'note_type' => 'common',
            'params' => ['text' => self::MARK . ' изменено'],
        ]]);
        self::assertSame([$note['id']], array_column($updated, 'id'));

        $found = $notes->findById('leads', $this->leadId(), $note['id']);
        self::assertSame(self::MARK . ' изменено', $found['params']['text'] ?? null);

        $list = $notes->findForEntity('leads', $this->leadId(), 'filter[note_type][0]=common', 250, null);
        self::assertContains($note['id'], array_column($list, 'id'));
    }

    public function testCreateAddsNotesOfDifferentTypesInOneRequest(): void
    {
        $notes = $this->amocrm()->notes();

        $created = $notes->create('leads', [
            [
                'entity_id' => $this->leadId(),
                'note_type' => 'common',
                'params' => ['text' => self::MARK . ' пачка'],
                'request_id' => 'common',
            ],
            [
                'entity_id' => $this->leadId(),
                'note_type' => 'service_message',
                'params' => ['service' => self::MARK, 'text' => 'служебное сообщение'],
                'request_id' => 'service',
            ],
            [
                'entity_id' => $this->leadId(),
                'note_type' => 'call_in',
                'params' => [
                    'uniq' => 'autotest-note-' . self::runToken(),
                    'duration' => 60,
                    'source' => 'autotest',
                    'phone' => self::runPhone(),
                ],
                'request_id' => 'call',
            ],
        ]);

        // Своё request_id возвращается рядом с id — по нему и сопоставляют.
        $types = [];

        foreach ($created as $note) {
            $types[$note['request_id']] = $notes->findById('leads', $this->leadId(), $note['id'])['note_type'] ?? null;
        }

        ksort($types);
        self::assertSame(['call' => 'call_in', 'common' => 'common', 'service' => 'service_message'], $types);
    }

    public function testFindByIdReturnsNullForMissingNote(): void
    {
        // amoCRM отвечает на несуществующее примечание 204 без тела.
        self::assertNull($this->amocrm()->notes()->findById('leads', $this->leadId(), 999999999));
    }
}
