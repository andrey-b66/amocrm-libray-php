<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

use Amocrm\Facade\Amocrm;
use PHPUnit\Framework\TestCase;

/**
 * Живые тесты: запросы уходят в настоящий аккаунт amoCRM из `.env`.
 *
 * Запускаются отдельно, командой `composer test-live`, и пропускаются, если в
 * `.env` нет DOMAIN, TOKEN, TEST_PIPELINE_ID или TEST_STATUS_ID. Удалить
 * сделку, контакт или компанию через API
 * нельзя, и всё созданное остаётся в аккаунте. Поэтому сделки заводятся только
 * в тестовой воронке, всё созданное помечается `[autotest]`, а сделка, контакт
 * и компания создаются по одной на весь прогон.
 */
abstract class LiveTestCase extends TestCase
{
    /** Пометка всего, что создают тесты. */
    protected const MARK = '[autotest]';

    private static ?Amocrm $amocrm = null;

    private static int $pipelineId = 0;

    private static int $statusId = 0;

    private static ?array $createdLead = null;

    private static ?array $createdContact = null;

    private static ?array $createdCompany = null;

    /** Метка прогона: по ней поиск находит именно сегодняшние записи. */
    private static ?string $runToken = null;

    /** Телефон контакта этого прогона: +7 900 и семь случайных цифр. */
    private static ?string $runPhone = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$amocrm !== null) {
            return;
        }

        $env = self::readEnv(dirname(__DIR__, 2) . '/.env');

        foreach (['DOMAIN', 'TOKEN', 'TEST_PIPELINE_ID', 'TEST_STATUS_ID'] as $key) {
            if (($env[$key] ?? '') === '') {
                self::markTestSkipped("В .env нет $key — живые тесты пропущены.");
            }
        }

        self::$pipelineId = (int) $env['TEST_PIPELINE_ID'];
        self::$statusId = (int) $env['TEST_STATUS_ID'];
        self::$amocrm = new Amocrm($env['DOMAIN'], $env['TOKEN']);
    }

    /** Воронка, в которой тестам разрешено создавать что угодно. */
    protected static function pipelineId(): int
    {
        return self::$pipelineId;
    }

    /** Этап тестовой воронки, на который встают созданные сделки. */
    protected static function statusId(): int
    {
        return self::$statusId;
    }

    protected function amocrm(): Amocrm
    {
        return self::$amocrm;
    }

    /**
     * Сделка этого прогона — ответ amoCRM на её создание.
     *
     * Создаётся при первом обращении и дальше переиспользуется всеми живыми
     * тестами, чтобы каждый прогон оставлял в воронке одну сделку, а не десяток.
     */
    protected function createdLead(): array
    {
        if (self::$createdLead === null) {
            [$lead] = $this->amocrm()->leads()->create([[
                'name' => self::MARK . ' ' . date('Y-m-d H:i:s'),
                'pipeline_id' => self::pipelineId(),
                'status_id' => self::statusId(),
                'request_id' => 'autotest',
            ]]);

            self::$createdLead = $lead;
        }

        return self::$createdLead;
    }

    protected function leadId(): int
    {
        return $this->createdLead()['id'];
    }

    /** Контакт этого прогона с телефоном runPhone(), один на все живые тесты. */
    protected function contactId(): int
    {
        if (self::$createdContact === null) {
            [self::$createdContact] = $this->amocrm()->contacts()->create([[
                'name' => self::MARK . ' Контакт ' . self::runToken(),
                'custom_fields_values' => [[
                    'field_code' => 'PHONE',
                    'values' => [['value' => self::runPhone(), 'enum_code' => 'WORK']],
                ]],
            ]]);
        }

        return self::$createdContact['id'];
    }

    /** Компания этого прогона, одна на все живые тесты. */
    protected function companyId(): int
    {
        if (self::$createdCompany === null) {
            [self::$createdCompany] = $this->amocrm()->companies()->create([[
                'name' => self::MARK . ' Компания ' . self::runToken(),
            ]]);
        }

        return self::$createdCompany['id'];
    }

    protected static function runToken(): string
    {
        return self::$runToken ??= 'at' . random_int(100000, 999999);
    }

    protected static function runPhone(): string
    {
        return self::$runPhone ??= '+7900' . random_int(1000000, 9999999);
    }

    /**
     * Повторять поиск, пока он не вернёт хоть что-то.
     *
     * Новые записи попадают в поиск amoCRM не сразу, а спустя несколько секунд.
     */
    protected static function eventually(callable $search, int $seconds = 30): array
    {
        $deadline = time() + $seconds;

        do {
            $result = $search();

            if ($result !== []) {
                return $result;
            }

            sleep(2);
        } while (time() < $deadline);

        return $result;
    }

    /** Разобрать `.env`: строки KEY=value, кавычки вокруг значения снимаются. */
    private static function readEnv(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $env = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim(trim($value), '"\'');
        }

        return $env;
    }
}
