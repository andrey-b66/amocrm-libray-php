<?php

declare(strict_types=1);

namespace Amocrm\Tests\Repository;

use Amocrm\Exception\ApiException;
use Amocrm\Facade\Amocrm;
use Amocrm\Repository\AbstractSearchableRepository;
use Amocrm\Repository\Company;
use Amocrm\Repository\Contact;
use Amocrm\Repository\Lead;
use Amocrm\Repository\Task;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

/**
 * Общие операции репозиториев. Проверяются на контактах: у остальных
 * репозиториев отличаются только эндпоинт и ключ в `_embedded`.
 */
final class AbstractApiRepositoryTest extends FakeAmocrmTestCase
{
    /** @dataProvider repositories */
    public function testRepositoryUsesItsEndpointAndEmbeddedKey(callable $repository, string $path, string $key): void
    {
        $this->respond(self::page($key, [['id' => 1]]));

        self::assertSame([['id' => 1]], $repository($this->amocrm())->find());
        self::assertSame(["GET $path?page=1&limit=250"], $this->requestLog());
    }

    public function repositories(): array
    {
        return [
            'контакты' => [fn (Amocrm $amocrm) => $amocrm->contacts(), '/api/v4/contacts', 'contacts'],
            'сделки' => [fn (Amocrm $amocrm) => $amocrm->leads(), '/api/v4/leads', 'leads'],
            'компании' => [fn (Amocrm $amocrm) => $amocrm->companies(), '/api/v4/companies', 'companies'],
            'задачи' => [fn (Amocrm $amocrm) => $amocrm->tasks(), '/api/v4/tasks', 'tasks'],
        ];
    }

    /**
     * У задач amoCRM не знает ни `query`, ни пользовательских полей и на такой
     * поиск молча отдала бы всю выборку.
     *
     * @dataProvider searchSupport
     */
    public function testSearchExistsOnlyWhereAmocrmSupportsIt(string $class, bool $hasSearch): void
    {
        foreach (['findByPhone', 'findByQuery', 'findByField'] as $method) {
            self::assertSame($hasSearch, method_exists($class, $method), "$class::$method()");
        }
    }

    public function searchSupport(): array
    {
        return [
            'контакты' => [Contact::class, true],
            'компании' => [Company::class, true],
            'сделки' => [Lead::class, true],
            'задачи' => [Task::class, false],
        ];
    }

    public function testCreateSendsListAndReturnsCreatedEntities(): void
    {
        $this->respond(self::page('contacts', [['id' => 1, 'request_id' => '42'], ['id' => 2, 'request_id' => '43']]));

        $created = $this->contacts()->create([
            ['name' => 'Иван', 'request_id' => '42'],
            ['name' => 'Пётр', 'request_id' => '43'],
        ]);

        self::assertSame([['id' => 1, 'request_id' => '42'], ['id' => 2, 'request_id' => '43']], $created);
        self::assertSame(['POST /api/v4/contacts'], $this->requestLog());
        self::assertSame(
            [['name' => 'Иван', 'request_id' => '42'], ['name' => 'Пётр', 'request_id' => '43']],
            $this->lastRequest()['body'],
        );
    }

    public function testCreateSplitsIntoBatchesOf250(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]]));
        $this->respond(self::page('contacts', [['id' => 2]]));

        $created = $this->contacts()->create(self::entities(251));

        self::assertSame([['id' => 1], ['id' => 2]], $created);

        $requests = $this->requests();
        self::assertCount(2, $requests);
        self::assertCount(250, $requests[0]['body']);
        self::assertSame([['name' => 'Контакт 251']], $requests[1]['body']);
    }

    public function testCreateSendsJsonArrayEvenWhenKeysHaveGaps(): void
    {
        $this->respond(self::page('contacts', []));

        $this->contacts()->create([0 => ['name' => 'Иван'], 2 => ['name' => 'Пётр']]);

        // Массив, а не объект {"0":…,"2":…}: иначе amoCRM запрос не примет.
        self::assertSame('[{"name":"Иван"},{"name":"Пётр"}]', $this->lastRequest()['rawBody']);
    }

    public function testCreateWithEmptyListSendsNothing(): void
    {
        self::assertSame([], $this->contacts()->create([]));
        self::assertSame([], $this->requests());
    }

    public function testCreateRejectsEntityThatIsNotArray(): void
    {
        $exception = self::exceptionFrom(fn () => $this->contacts()->create([['name' => 'Иван'], 'Пётр']));

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertStringContainsString('Элемент 1', $exception->getMessage());
        self::assertSame([], $this->requests());
    }

    public function testCreateStopsAtFailedBatch(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]]));
        $this->respond(['detail' => 'Ошибка в порции'], 400);

        $exception = self::exceptionFrom(fn () => $this->contacts()->create(self::entities(501)));

        // Первая порция уже создана, третья не отправлялась.
        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame(400, $exception->getCode());
        self::assertCount(2, $this->requests());
    }

    public function testFindByIdReturnsEntity(): void
    {
        $this->respond(['id' => 15, 'name' => 'Иван']);

        self::assertSame(['id' => 15, 'name' => 'Иван'], $this->contacts()->findById(15, ' leads,companies '));
        self::assertSame(['GET /api/v4/contacts/15?with=leads,companies'], $this->requestLog());
    }

    public function testFindByIdWithoutWith(): void
    {
        $this->respond(['id' => 15]);

        $this->contacts()->findById(15);

        self::assertSame(['GET /api/v4/contacts/15'], $this->requestLog());
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $this->respond(['title' => 'Not Found'], 404);

        self::assertNull($this->contacts()->findById(15));
    }

    public function testFindByIdReturnsNullOnNoContent(): void
    {
        $this->respondNoContent();

        self::assertNull($this->contacts()->findById(15));
    }

    public function testFindByIdRethrowsOtherErrors(): void
    {
        $this->respond([], 500);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(500);

        $this->contacts()->findById(15);
    }

    public function testFindReadsOnePageByDefault(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]], true));

        self::assertSame([['id' => 1]], $this->contacts()->find());
        self::assertSame(['GET /api/v4/contacts?page=1&limit=250'], $this->requestLog());
    }

    public function testFindAcceptsFullUrlAndIgnoresPageAndLimitFromIt(): void
    {
        $this->respond(self::page('contacts', []));

        $this->contacts()->find('https://example.amocrm.ru/contacts?query=Иван&page=5&limit=10&with=leads', 50);

        self::assertSame(['GET /api/v4/contacts?query=Иван&with=leads&page=1&limit=50'], $this->requestLog());
    }

    public function testFindReadsAllPagesUntilNoNextLink(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]], true));
        $this->respond(self::page('contacts', [['id' => 2]], true));
        $this->respond(self::page('contacts', [['id' => 3]]));

        self::assertSame([['id' => 1], ['id' => 2], ['id' => 3]], $this->contacts()->find('order[id]=asc', 1, null));
        self::assertSame([
            'GET /api/v4/contacts?order[id]=asc&page=1&limit=1',
            'GET /api/v4/contacts?order[id]=asc&page=2&limit=1',
            'GET /api/v4/contacts?order[id]=asc&page=3&limit=1',
        ], $this->requestLog());
    }

    public function testFindStopsAfterRequestedNumberOfPages(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]], true));
        $this->respond(self::page('contacts', [['id' => 2]], true));

        self::assertSame([['id' => 1], ['id' => 2]], $this->contacts()->find('', 250, 2));
        self::assertCount(2, $this->requests());
    }

    public function testFindStopsOnEmptyPageEvenWithNextLink(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]], true));
        $this->respond(self::page('contacts', [], true));

        self::assertSame([['id' => 1]], $this->contacts()->find('', 250, null));
        self::assertCount(2, $this->requests());
    }

    public function testFindReturnsEmptyListOnNoContent(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->contacts()->find('', 250, null));
    }

    /** @dataProvider invalidPageCounts */
    public function testFindRejectsNonPositivePageCount(int $pages): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contacts()->find('', 250, $pages);
    }

    public function invalidPageCounts(): array
    {
        return ['ноль' => [0], 'минус' => [-1]];
    }

    public function testFindByIdsKeepsOrderOfIdsAndSkipsMissing(): void
    {
        // amoCRM отдаёт сущности в своём порядке, а 20 не нашлось вовсе.
        $this->respond(self::page('contacts', [['id' => 30], ['id' => 10]]));

        $contacts = $this->contacts()->findByIds([10, 20, 30, 10], 'leads');

        self::assertSame([['id' => 10], ['id' => 30]], $contacts);
        self::assertSame(
            ['GET /api/v4/contacts?filter[id][0]=10&filter[id][1]=20&filter[id][2]=30&page=1&limit=3&with=leads'],
            $this->requestLog(),
        );
    }

    public function testFindByIdsSplitsIntoBatchesOf25(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]]));
        $this->respond(self::page('contacts', [['id' => 26]]));

        self::assertSame([['id' => 1], ['id' => 26]], $this->contacts()->findByIds(range(1, 26)));

        $log = $this->requestLog();
        self::assertCount(2, $log);
        self::assertStringEndsWith('filter[id][24]=25&page=1&limit=25', $log[0]);
        self::assertSame('GET /api/v4/contacts?filter[id][0]=26&page=1&limit=1', $log[1]);
    }

    public function testFindByIdsAcceptsNumericStrings(): void
    {
        $this->respond(self::page('contacts', [['id' => 10]]));

        self::assertSame([['id' => 10]], $this->contacts()->findByIds(['10']));
    }

    public function testFindByIdsWithEmptyListSendsNothing(): void
    {
        self::assertSame([], $this->contacts()->findByIds([]));
        self::assertSame([], $this->requests());
    }

    public function testUpdateSendsPatchAndReturnsUpdatedEntities(): void
    {
        $this->respond(self::page('contacts', [['id' => 10, 'name' => 'Пётр']]));

        self::assertSame([['id' => 10, 'name' => 'Пётр']], $this->contacts()->update([['id' => 10, 'name' => 'Пётр']]));
        self::assertSame(['PATCH /api/v4/contacts'], $this->requestLog());
        self::assertSame([['id' => 10, 'name' => 'Пётр']], $this->lastRequest()['body']);
    }

    public function testUpdateSplitsIntoBatchesOf250(): void
    {
        $this->respond(self::page('contacts', []));
        $this->respond(self::page('contacts', []));

        $entities = [];

        foreach (range(1, 251) as $id) {
            $entities[] = ['id' => $id];
        }

        $this->contacts()->update($entities);

        $requests = $this->requests();
        self::assertCount(2, $requests);
        self::assertCount(250, $requests[0]['body']);
        self::assertSame([['id' => 251]], $requests[1]['body']);
    }

    public function testUpdateRejectsEntityWithoutId(): void
    {
        $exception = self::exceptionFrom(fn () => $this->contacts()->update([['id' => 1], ['name' => 'Пётр']]));

        self::assertInstanceOf(InvalidArgumentException::class, $exception);
        self::assertStringContainsString('В сущности 1', $exception->getMessage());
        self::assertSame([], $this->requests());
    }

    public function testUpdateRejectsEntityThatIsNotArray(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contacts()->update([10]);
    }

    public function testUpdateWithEmptyListSendsNothing(): void
    {
        self::assertSame([], $this->contacts()->update([]));
        self::assertSame([], $this->requests());
    }

    public function testFindByFieldFiltersByFieldValue(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]]));

        self::assertSame([['id' => 1]], $this->contacts()->findByField(123456, ' ООО Ромашка ', 50, 'leads'));
        self::assertSame(
            ['GET /api/v4/contacts?filter[custom_fields_values][123456][0]=ООО Ромашка&with=leads&page=1&limit=50'],
            $this->requestLog(),
        );
    }

    /**
     * @dataProvider scalarFieldValues
     *
     * @param int|float|string|bool $value
     */
    public function testFindByFieldConvertsScalarValue($value, string $expected): void
    {
        $this->respond(self::page('contacts', []));

        $this->contacts()->findByField(1, $value);

        self::assertSame("filter[custom_fields_values][1][0]=$expected&page=1&limit=250", $this->lastRequest()['query']);
    }

    public function scalarFieldValues(): array
    {
        return [
            'true' => [true, '1'],
            'false' => [false, '0'],
            'целое' => [15, '15'],
            'дробное' => [1.5, '1.5'],
        ];
    }

    public function testFindByFieldRejectsNonScalarValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->contacts()->findByField(1, ['ООО Ромашка']);
    }

    public function testFindByQuerySearchesByText(): void
    {
        $this->respond(self::page('contacts', [['id' => 1]]));

        self::assertSame([['id' => 1]], $this->contacts()->findByQuery(' Ромашка ', 25, 'leads'));
        self::assertSame(['GET /api/v4/contacts?query=Ромашка&with=leads&page=1&limit=25'], $this->requestLog());
    }

    public function testFindByQueryWithBlankTextSendsNothing(): void
    {
        self::assertSame([], $this->contacts()->findByQuery('   '));
        self::assertSame([], $this->requests());
    }

    /** @dataProvider phones */
    public function testFindByPhoneSearchesByLastTenDigits(string $phone, string $expectedSearch): void
    {
        $this->respond(self::page('contacts', []));

        $this->contacts()->findByPhone($phone);

        self::assertSame(["GET /api/v4/contacts?query=$expectedSearch&page=1&limit=250"], $this->requestLog());
    }

    public function phones(): array
    {
        return [
            'с плюсом и скобками' => ['+7 (999) 000-00-00', '9990000000'],
            'через восьмёрку' => ['8 999 000 00 00', '9990000000'],
            'слитно' => ['79990000000', '9990000000'],
            'другая страна' => ['+375 (29) 123-45-67', '5291234567'],
            'короткий номер целиком' => ['12-34-5', '12345'],
        ];
    }

    public function testFindByPhoneWithoutDigitsSendsNothing(): void
    {
        self::assertSame([], $this->contacts()->findByPhone('нет номера'));
        self::assertSame([], $this->requests());
    }

    private function contacts(): AbstractSearchableRepository
    {
        return $this->amocrm()->contacts();
    }

    /** Список из $count контактов: «Контакт 1», «Контакт 2»… */
    private static function entities(int $count): array
    {
        $entities = [];

        for ($number = 1; $number <= $count; $number++) {
            $entities[] = ['name' => "Контакт $number"];
        }

        return $entities;
    }
}
