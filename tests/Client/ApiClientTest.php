<?php

declare(strict_types=1);

namespace Amocrm\Tests\Client;

use Amocrm\Client\ApiClient;
use Amocrm\Exception\ApiException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Запросы уходят на встроенный PHP-сервер, который отвечает тем, каким
 * запрос до него дошёл, — см. server.php рядом.
 *
 * @requires extension curl
 */
final class ApiClientTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static string $serverUrl;

    public static function setUpBeforeClass(): void
    {
        $port = self::freePort();

        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/server.php'],
            [
                ['pipe', 'r'],
                ['file', self::nullDevice(), 'w'],
                ['file', self::nullDevice(), 'w'],
            ],
            $pipes,
        );

        if (!is_resource(self::$server)) {
            throw new RuntimeException('Не удалось запустить тестовый сервер.');
        }

        self::$serverUrl = "http://127.0.0.1:$port/";
        self::waitForPort($port);
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;
    }

    /** @dataProvider writeMethods */
    public function testWriteMethodSendsJsonBody(string $method): void
    {
        $response = $this->client()->$method('api/v4/leads', [['name' => 'Сделка/1', 'price' => 10]]);

        self::assertSame(strtoupper($method), $response['method']);
        self::assertSame('/api/v4/leads', $response['uri']);
        // Кириллица и слеши уходят как есть, без С и \/.
        self::assertSame('[{"name":"Сделка/1","price":10}]', $response['body']);
    }

    /** @dataProvider writeMethods */
    public function testWriteMethodWithoutDataSendsEmptyBody(string $method): void
    {
        $response = $this->client()->$method('api/v4/leads/1/unlink');

        self::assertSame(strtoupper($method), $response['method']);
        self::assertSame('', $response['body']);
        // Без Content-Length часть серверов отвечает на такой запрос ошибкой 411.
        self::assertSame('0', $response['headers']['content-length'] ?? null);
    }

    public function writeMethods(): array
    {
        return [
            'post' => ['post'],
            'patch' => ['patch'],
            'put' => ['put'],
        ];
    }

    public function testGetSendsNoBody(): void
    {
        $response = $this->client()->get('api/v4/leads');

        self::assertSame('GET', $response['method']);
        self::assertSame('', $response['body']);
        self::assertArrayNotHasKey('content-length', $response['headers']);
    }

    public function testSendsAuthorizationAndJsonHeaders(): void
    {
        $headers = $this->client(' token-123 ')->get('api/v4/account')['headers'];

        // Пробелы вокруг токена отрезаются.
        self::assertSame('Bearer token-123', $headers['authorization']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('application/json', $headers['accept']);
        self::assertSame('integrat-amocrm', $headers['user-agent']);
    }

    /** @dataProvider queries */
    public function testBuildsUrl(string $endpoint, string $query, string $expectedUri): void
    {
        self::assertSame($expectedUri, $this->client()->get($endpoint, $query)['uri']);
    }

    public function queries(): array
    {
        return [
            'без параметров' => ['api/v4/leads', '', '/api/v4/leads'],
            'слеш в начале эндпоинта' => ['/api/v4/leads', '', '/api/v4/leads'],
            'скобки' => [
                'api/v4/leads',
                'filter[status_id][0]=143&limit=50',
                '/api/v4/leads?filter%5Bstatus_id%5D%5B0%5D=143&limit=50',
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

        self::assertSame($data, $this->client()->post('status/200', $data));
    }

    public function testNoContentReturnsEmptyArray(): void
    {
        self::assertSame([], $this->client()->get('empty'));
    }

    public function testNonJsonResponseReturnsEmptyArray(): void
    {
        self::assertSame([], $this->client()->get('not-json'));
    }

    public function testHttpErrorThrowsApiException(): void
    {
        $responseData = [
            'title' => 'Bad Request',
            'detail' => 'Поле name обязательно',
            'validation-errors' => [['request_id' => '0', 'errors' => [['path' => 'name']]]],
        ];

        try {
            $this->client()->post('status/400', $responseData);
            self::fail('Ожидалось ApiException.');
        } catch (ApiException $exception) {
            self::assertSame('amoCRM отклонила данные запроса. Поле name обязательно', $exception->getMessage());
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame(400, $exception->getCode());
            self::assertSame('POST', $exception->getHttpMethod());
            self::assertSame('status/400', $exception->getEndpoint());
            self::assertSame($responseData, $exception->getResponseData());
            self::assertSame($responseData['validation-errors'], $exception->getValidationErrors());
        }
    }

    /** @dataProvider errorStatuses */
    public function testErrorMessageDependsOnStatus(int $statusCode, string $expectedMessage): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionCode($statusCode);
        $this->expectExceptionMessage($expectedMessage);

        $this->client()->get("status/$statusCode");
    }

    public function errorStatuses(): array
    {
        return [
            '401' => [401, 'Долгосрочный токен amoCRM недействителен или отозван.'],
            '403' => [403, 'Недостаточно прав для выполнения запроса к amoCRM.'],
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
        $client = $this->client('token', 'http://127.0.0.1:' . self::freePort() . '/');

        try {
            $client->get('api/v4/leads');
            self::fail('Ожидалось ApiException.');
        } catch (ApiException $exception) {
            self::assertStringStartsWith('Не удалось выполнить запрос к amoCRM.', $exception->getMessage());
            self::assertSame(0, $exception->getStatusCode());
            self::assertSame('GET', $exception->getHttpMethod());
            self::assertSame('api/v4/leads', $exception->getEndpoint());
        }
    }

    public function testInvalidUtf8ThrowsBeforeSending(): void
    {
        // Сервера по этому адресу нет: уйди запрос в сеть, ошибка была бы про соединение.
        $client = $this->client('token', 'http://127.0.0.1:' . self::freePort() . '/');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Не удалось закодировать данные запроса в JSON.');

        $client->post('api/v4/leads', [['name' => "\xB1\x31"]]);
    }

    public function testClientStaysUsableAfterError(): void
    {
        $client = $this->client();

        try {
            $client->post('status/400', ['detail' => 'ошибка']);
            self::fail('Ожидалось ApiException.');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->getStatusCode());
        }

        // Настройки прошлого запроса не протекают в следующий: у GET нет тела.
        $response = $client->get('api/v4/leads', 'page=2');

        self::assertSame('GET', $response['method']);
        self::assertSame('/api/v4/leads?page=2', $response['uri']);
        self::assertSame('', $response['body']);
    }

    /** @dataProvider domains */
    public function testNormalizesDomain(string $domain): void
    {
        $client = new ApiClient($domain, 'token');

        self::assertSame('https://example.amocrm.ru/', self::baseUrl($client)->getValue($client));
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

    /**
     * Клиент на тестовом сервере.
     *
     * Сам клиент всегда ходит по https, а тестовый сервер понимает только
     * http, поэтому адрес подменяется в обход конструктора.
     */
    private function client(string $token = 'token', ?string $baseUrl = null): ApiClient
    {
        $client = new ApiClient('example.amocrm.ru', $token);
        self::baseUrl($client)->setValue($client, $baseUrl ?? self::$serverUrl);

        return $client;
    }

    private static function baseUrl(ApiClient $client): ReflectionProperty
    {
        $property = new ReflectionProperty($client, 'baseUrl');
        $property->setAccessible(true);

        return $property;
    }

    /** Порт, который прямо сейчас никто не слушает. */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Не удалось найти свободный порт.');
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private static function waitForPort(int $port): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }

        throw new RuntimeException("Тестовый сервер не поднялся на порту $port.");
    }

    private static function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }
}
