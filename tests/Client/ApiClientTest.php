<?php

declare(strict_types=1);

namespace Amocrm\Tests\Client;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use Amocrm\Tests\Fake\FakeAmocrmTestCase;
use InvalidArgumentException;

/** HTTP-клиент: тело и заголовки запросов, адреса, ответы, ошибки и настройки. */
final class ApiClientTest extends FakeAmocrmTestCase
{
    /** @dataProvider writeMethods */
    public function testWriteMethodSendsJsonBody(string $method): void
    {
        $this->respond(['id' => 1]);

        $response = $this->client()->$method('api/v4/leads', [['name' => 'Сделка/1', 'price' => 10]]);

        self::assertSame(['id' => 1], $response);

        $request = $this->lastRequest();
        self::assertSame(strtoupper($method), $request['method']);
        self::assertSame('/api/v4/leads', $request['uri']);
        // Кириллица и слеши уходят как есть, без экранирования.
        self::assertSame('[{"name":"Сделка/1","price":10}]', $request['rawBody']);
    }

    /** @dataProvider writeMethods */
    public function testWriteMethodWithoutDataSendsEmptyBody(string $method): void
    {
        $this->respondNoContent();

        $this->client()->$method('api/v4/leads/1/unlink');

        $request = $this->lastRequest();
        self::assertSame(strtoupper($method), $request['method']);
        self::assertSame('', $request['rawBody']);
        // Без Content-Length часть серверов отвечает на такой запрос ошибкой 411.
        self::assertSame('0', $request['headers']['content-length'] ?? null);
    }

    public function writeMethods(): array
    {
        return [
            'post' => ['post'],
            'patch' => ['patch'],
        ];
    }

    public function testGetSendsNoBody(): void
    {
        $this->respond([]);

        $this->client()->get('api/v4/leads');

        $request = $this->lastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('', $request['rawBody']);
        self::assertArrayNotHasKey('content-length', $request['headers']);
    }

    public function testSendsAuthorizationAndJsonHeaders(): void
    {
        $this->respond([]);

        $this->client(' token-123 ')->get('api/v4/account');

        $headers = $this->lastRequest()['headers'];
        // Пробелы вокруг токена отрезаются.
        self::assertSame('Bearer token-123', $headers['authorization']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('application/json', $headers['accept']);
        self::assertSame('integrat-amocrm', $headers['user-agent']);
    }

    /** @dataProvider queries */
    public function testBuildsUrl(string $endpoint, string $query, string $expectedUri): void
    {
        $this->respond([]);

        $this->client()->get($endpoint, $query);

        self::assertSame($expectedUri, $this->lastRequest()['uri']);
    }

    public function queries(): array
    {
        return [
            'без параметров' => ['api/v4/leads', '', '/api/v4/leads'],
            'слеш в начале эндпоинта' => ['/api/v4/leads', '', '/api/v4/leads'],
            'скобки' => [
                'api/v4/leads',
                'filter[pipeline_id][0]=10739150&limit=50',
                '/api/v4/leads?filter%5Bpipeline_id%5D%5B0%5D=10739150&limit=50',
            ],
            'кириллица и пробел' => [
                'api/v4/contacts',
                'query=Ромашка ООО',
                '/api/v4/contacts?query=%D0%A0%D0%BE%D0%BC%D0%B0%D1%88%D0%BA%D0%B0%20%D0%9E%D0%9E%D0%9E',
            ],
            'уже закодированное не кодируется второй раз' => [
                'api/v4/contacts',
                'query=%D0%9E%20',
                '/api/v4/contacts?query=%D0%9E%20',
            ],
            'одиночный процент' => ['api/v4/leads', 'discount=100%', '/api/v4/leads?discount=100%25'],
            'знак вопроса и амперсанды по краям' => ['api/v4/leads', ' ?&limit=5& ', '/api/v4/leads?limit=5'],
        ];
    }

    public function testReturnsDecodedResponse(): void
    {
        $data = ['_embedded' => ['leads' => [['id' => 1, 'name' => 'Сделка']]]];
        $this->respond($data);

        self::assertSame($data, $this->client()->get('api/v4/leads'));
    }

    public function testNoContentReturnsEmptyArray(): void
    {
        $this->respondNoContent();

        self::assertSame([], $this->client()->get('api/v4/leads'));
    }

    public function testNonJsonResponseReturnsEmptyArray(): void
    {
        $this->respondRaw('<html>Внутренняя ошибка</html>');

        self::assertSame([], $this->client()->get('api/v4/leads'));
    }

    public function testHttpErrorThrowsApiException(): void
    {
        $responseData = [
            'title' => 'Bad Request',
            'detail' => 'Поле name обязательно',
            'validation-errors' => [['request_id' => '0', 'errors' => [['path' => 'name']]]],
        ];
        $this->respond($responseData, 400, ['X-Request-Id' => 'a614076704b6a3a3c4a8f15eca606239']);

        $exception = self::exceptionFrom(fn () => $this->client()->post('api/v4/leads', [['price' => 1]]));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertSame('amoCRM отклонила данные запроса. Поле name обязательно', $exception->getMessage());
        self::assertSame('a614076704b6a3a3c4a8f15eca606239', $exception->getRequestId());
        self::assertSame(400, $exception->getStatusCode());
        self::assertSame(400, $exception->getCode());
        self::assertSame('POST', $exception->getHttpMethod());
        self::assertSame('api/v4/leads', $exception->getEndpoint());
        self::assertSame($responseData, $exception->getResponseData());
        self::assertSame($responseData['validation-errors'], $exception->getValidationErrors());
    }

    /** @dataProvider errorStatuses */
    public function testErrorMessageDependsOnStatus(int $statusCode, string $expectedMessage): void
    {
        $this->respond([], $statusCode);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode($statusCode);
        $this->expectExceptionMessage($expectedMessage);

        $this->client()->get('api/v4/leads');
    }

    public function errorStatuses(): array
    {
        return [
            '401' => [401, 'Токен amoCRM истёк, недействителен или отозван.'],
            '402' => [402, 'Аккаунт amoCRM не оплачен или возможность не входит в тариф.'],
            // Тем же кодом amoCRM отвечает на блокировку за частые запросы.
            '403' => [403, 'amoCRM отклонила запрос: недостаточно прав или аккаунт заблокирован.'],
            '404' => [404, 'Запрошенный ресурс amoCRM не найден.'],
            '429' => [429, 'Превышен лимит запросов к amoCRM.'],
            '500' => [500, 'Сервис amoCRM временно недоступен.'],
            '503' => [503, 'Сервис amoCRM временно недоступен.'],
            '418' => [418, 'amoCRM вернула HTTP-ошибку 418.'],
            // Редиректы не проходятся: адрес amoCRM API их не отдаёт.
            '301' => [301, 'amoCRM вернула HTTP-ошибку 301.'],
        ];
    }

    public function testConnectionFailureThrowsApiExceptionWithZeroStatus(): void
    {
        $client = $this->client();
        self::setBaseUrl($client, 'http://127.0.0.1:' . self::freePort() . '/');

        $exception = self::exceptionFrom(fn () => $client->get('api/v4/leads'));

        self::assertInstanceOf(ApiException::class, $exception);
        self::assertStringStartsWith('Не удалось выполнить запрос к amoCRM.', $exception->getMessage());
        // Код cURL есть всегда, даже при пустом описании: 7 — не удалось соединиться.
        self::assertStringEndsWith('Код cURL: 7.', $exception->getMessage());
        self::assertSame(0, $exception->getStatusCode());
        self::assertSame('GET', $exception->getHttpMethod());
        self::assertSame('api/v4/leads', $exception->getEndpoint());
        self::assertSame('', $exception->getRequestId(), 'Ответа не было — и ID запроса нет.');
    }

    public function testRemembersRequestIdOfEachResponse(): void
    {
        // Регистр заголовка у разных серверов разный.
        $this->respond(['id' => 1], 200, ['x-request-id' => ' id-1 ']);
        $this->respond([]);
        $client = $this->client();

        self::assertSame('', $client->lastRequestId(), 'До первого запроса ID нет.');

        $client->get('api/v4/leads/1');
        self::assertSame('id-1', $client->lastRequestId());

        $client->get('api/v4/leads/2');
        self::assertSame('', $client->lastRequestId(), 'ID прошлого ответа не переносится на следующий.');
    }

    public function testInvalidUtf8ThrowsBeforeSending(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Не удалось закодировать данные запроса в JSON.');

        try {
            $this->client()->post('api/v4/leads', [['name' => "\xB1\x31"]]);
        } finally {
            self::assertSame([], $this->requests());
        }
    }

    public function testClientStaysUsableAfterError(): void
    {
        $this->respond(['detail' => 'ошибка'], 400);
        $this->respond(['ok' => true]);
        $client = $this->client();

        self::assertInstanceOf(ApiException::class, self::exceptionFrom(fn () => $client->post('api/v4/leads', ['a' => 1])));
        self::assertSame(['ok' => true], $client->get('api/v4/leads', 'page=2'));

        // Настройки прошлого запроса не протекают в следующий: у GET нет тела.
        $request = $this->lastRequest();
        self::assertSame('GET', $request['method']);
        self::assertSame('/api/v4/leads?page=2', $request['uri']);
        self::assertSame('', $request['rawBody']);
    }

    /** @dataProvider domains */
    public function testNormalizesDomain(string $domain): void
    {
        self::assertSame('https://example.amocrm.ru/', self::baseUrlOf(new ApiClient($domain, 'token')));
    }

    public function domains(): array
    {
        return [
            'как есть' => ['example.amocrm.ru'],
            'с https и слешем' => ['https://example.amocrm.ru/'],
            'http меняется на https' => ['http://example.amocrm.ru'],
            'регистр и пробелы' => ['  HTTPS://Example.AmoCRM.ru  '],
        ];
    }

    /** @dataProvider invalidCredentials */
    public function testRejectsEmptyCredentials(string $domain, string $token): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ApiClient($domain, $token);
    }

    public function invalidCredentials(): array
    {
        return [
            'пустой домен' => ['', 'token'],
            'домен из одной схемы' => ['https://', 'token'],
            'пустой токен' => ['example.amocrm.ru', ''],
            'токен из пробелов' => ['example.amocrm.ru', '   '],
        ];
    }

    public function testUsesGivenTimeouts(): void
    {
        $client = new ApiClient('example.amocrm.ru', 'token', 90, 5);

        self::assertSame(90, self::privateProperty($client, 'timeout'));
        self::assertSame(5, self::privateProperty($client, 'connectTimeout'));
    }

    public function testDefaultTimeouts(): void
    {
        $client = new ApiClient('example.amocrm.ru', 'token');

        self::assertSame(ApiClient::DEFAULT_TIMEOUT, self::privateProperty($client, 'timeout'));
        self::assertSame(ApiClient::DEFAULT_CONNECT_TIMEOUT, self::privateProperty($client, 'connectTimeout'));
    }

    /** @dataProvider invalidTimeouts */
    public function testRejectsNonPositiveTimeouts(int $timeout, int $connectTimeout): void
    {
        // Ноль для cURL означает «ждать без конца».
        $this->expectException(InvalidArgumentException::class);

        new ApiClient('example.amocrm.ru', 'token', $timeout, $connectTimeout);
    }

    public function invalidTimeouts(): array
    {
        return [
            'ноль на ответ' => [0, 10],
            'минус на ответ' => [-1, 10],
            'ноль на соединение' => [30, 0],
        ];
    }
}
