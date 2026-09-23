<?php

declare(strict_types=1);

namespace Amocrm\Tests\Facade;

use Amocrm\Client\ApiClient;
use Amocrm\Facade\Amocrm;
use Amocrm\Repository\Call;
use Amocrm\Repository\Company;
use Amocrm\Repository\Contact;
use Amocrm\Repository\Lead;
use Amocrm\Repository\Link;
use Amocrm\Repository\Note;
use Amocrm\Repository\Pipeline;
use Amocrm\Repository\Tag;
use Amocrm\Repository\Task;
use Amocrm\Repository\User;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AmocrmTest extends TestCase
{
    /** @dataProvider repositories */
    public function testReturnsRepository(string $method, string $expectedClass): void
    {
        self::assertInstanceOf($expectedClass, $this->amocrm()->$method());
    }

    public function repositories(): array
    {
        return [
            'contacts' => ['contacts', Contact::class],
            'leads' => ['leads', Lead::class],
            'companies' => ['companies', Company::class],
            'notes' => ['notes', Note::class],
            'tasks' => ['tasks', Task::class],
            'users' => ['users', User::class],
            'calls' => ['calls', Call::class],
            'links' => ['links', Link::class],
            'pipelines' => ['pipelines', Pipeline::class],
            'tags' => ['tags', Tag::class],
        ];
    }

    public function testRawReturnsSharedClient(): void
    {
        $amocrm = $this->amocrm();

        self::assertInstanceOf(ApiClient::class, $amocrm->raw());
        self::assertSame($amocrm->raw(), $amocrm->raw());
    }

    public function testRejectsEmptyCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Amocrm('example.amocrm.ru', '');
    }

    private function amocrm(): Amocrm
    {
        return new Amocrm('example.amocrm.ru', 'token');
    }
}
