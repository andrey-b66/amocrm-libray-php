<?php

declare(strict_types=1);

namespace Amocrm\Tests\Live;

use Amocrm\Facade\Amocrm;
use Amocrm\Tests\CatchesExceptions;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Живые тесты на настоящем аккаунте amoCRM из `.env`: `composer test-live`.
 *
 * Без DOMAIN, TOKEN, TEST_PIPELINE_ID или TEST_STATUS_ID они пропускаются.
 * Удалить сделку, контакт или компанию через API нельзя, поэтому сделки
 * заводятся только в тестовой воронке, всё созданное помечается `[autotest]`,
 * а сделка, контакт и компания создаются по одной на весь прогон.
 */
abstract class LiveTestCase extends TestCase
{
    use CatchesExceptions;

    /** Пометка всего, что создают тесты. */
    protected const MARK = '[autotest]';

    private static ?Amocrm $amocrm = null;

    private static string $domain = '';

    private static int $pipelineId = 0;

    private static int $statusId = 0;

    private static ?array $createdLead = null;

    private static ?array $createdContact = null;

    private static ?array $createdCompany = null;

    private static ?string $runToken = null;

    private static ?string $runPhone = null;

    /** Прочитать `.env` один раз на прогон; без нужных значений тест пропускается. */
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

        self::$domain = $env['DOMAIN'];
        self::$pipelineId = (int) $env['TEST_PIPELINE_ID'];
        self::$statusId = (int) $env['TEST_STATUS_ID'];
        self::$amocrm = new Amocrm($env['DOMAIN'], $env['TOKEN']);
    }

    /** Домен аккаунта из `.env`. */
    protected static function domain(): string
    {
        return self::$domain;
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

    /** Фасад на живой аккаунт. */
    protected function amocrm(): Amocrm
    {
        return self::$amocrm;
    }

    /**
     * Сделка этого прогона — ответ amoCRM на её создание. Создаётся при первом
     * обращении и общая для всех живых тестов: прогон оставляет одну сделку.
     */
    protected function createdLead(): array
    {
        if (self::$createdLead === null) {
            [$lead] = $this->amocrm()->leads()->create([[
                'name' => self::MARK . ' ' . self::runToken() . ' ' . date('Y-m-d H:i:s'),
                'pipeline_id' => self::pipelineId(),
                'status_id' => self::statusId(),
                'request_id' => 'autotest',
            ]]);

            self::$createdLead = $lead;
        }

        return self::$createdLead;
    }

    /** ID сделки этого прогона. */
    protected function leadId(): int
    {
        return $this->createdLead()['id'];
    }

    /** Контакт этого прогона с телефоном runPhone() — ответ amoCRM на создание, один на все тесты. */
    protected function createdContact(): array
    {
        if (self::$createdContact === null) {
            [self::$createdContact] = $this->amocrm()->contacts()->create([[
                'name' => self::MARK . ' Контакт ' . self::runToken(),
                'custom_fields_values' => [[
                    'field_code' => 'PHONE',
                    'values' => [['value' => self::runPhone(), 'enum_code' => 'WORK']],
                ]],
                'request_id' => 'autotest-contact',
            ]]);
        }

        return self::$createdContact;
    }

    /** ID контакта этого прогона. */
    protected function contactId(): int
    {
        return $this->createdContact()['id'];
    }

    /** Компания этого прогона — ответ amoCRM на создание, одна на все тесты. */
    protected function createdCompany(): array
    {
        if (self::$createdCompany === null) {
            [self::$createdCompany] = $this->amocrm()->companies()->create([[
                'name' => self::MARK . ' Компания ' . self::runToken(),
                'request_id' => 'autotest-company',
            ]]);
        }

        return self::$createdCompany;
    }

    /** ID компании этого прогона. */
    protected function companyId(): int
    {
        return $this->createdCompany()['id'];
    }

    /** Метка прогона в названиях: по ней поиск находит записи именно этого прогона. */
    protected static function runToken(): string
    {
        return self::$runToken ??= 'at' . random_int(100000, 999999);
    }

    /** Телефон контакта этого прогона: +7900 и семь случайных цифр. */
    protected static function runPhone(): string
    {
        return self::$runPhone ??= '+7900' . random_int(1000000, 9999999);
    }

    /**
     * Повторять действие до 30 секунд, пока оно не вернёт непустой результат:
     * новые записи попадают в поиск amoCRM не сразу. $isEarly решает, какое
     * исключение тоже значит «ещё рано»; остальные пробрасываются сразу.
     */
    protected static function eventually(callable $action, ?callable $isEarly = null): array
    {
        $deadline = time() + 30;

        while (true) {
            try {
                $result = $action();

                if ($result !== [] || time() >= $deadline) {
                    return $result;
                }
            } catch (Throwable $exception) {
                if ($isEarly === null || !$isEarly($exception) || time() >= $deadline) {
                    throw $exception;
                }
            }

            sleep(2);
        }
    }

    /** Значения пользовательского поля сущности по коду поля, например `PHONE`. */
    protected static function fieldValues(array $entity, string $fieldCode): array
    {
        foreach ($entity['custom_fields_values'] ?? [] as $field) {
            if (($field['field_code'] ?? null) === $fieldCode) {
                return array_column($field['values'] ?? [], 'value');
            }
        }

        return [];
    }

    /** ID пользовательского поля сущности по коду поля; тест падает, если поля нет. */
    protected static function fieldId(array $entity, string $fieldCode): int
    {
        foreach ($entity['custom_fields_values'] ?? [] as $field) {
            if (($field['field_code'] ?? null) === $fieldCode) {
                return $field['field_id'];
            }
        }

        self::fail("У сущности нет поля $fieldCode.");
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
