<?php

declare(strict_types=1);

namespace Amocrm\Tests\Fake;

use Amocrm\Client\ApiClient;
use Amocrm\Facade\Amocrm;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Тесты поверх фейковой amoCRM — встроенного PHP-сервера из server.php.
 *
 * Тест заранее кладёт в очередь ответы, сервер отдаёт их по порядку, а потом
 * тест проверяет, какие запросы до сервера дошли. После каждого теста очередь
 * должна опустеть: оставшийся ответ значит, что запросов ушло меньше, чем
 * ожидалось. Сервер один на весь прогон и останавливается вместе с PHPUnit.
 *
 * Пример: $this->respond(self::page('leads', [['id' => 1]]));
 *         $leads = $this->amocrm()->leads()->find();
 *         self::assertSame(['GET /api/v4/leads?page=1&limit=250'], $this->requestLog());
 *
 * @requires extension curl
 */
abstract class FakeAmocrmTestCase extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::startServer();
        file_put_contents(self::stateFile('responses.json'), '[]');
        file_put_contents(self::stateFile('requests.jsonl'), '');
    }

    protected function assertPostConditions(): void
    {
        self::assertSame(
            [],
            $this->pendingResponses(),
            'Не все заготовленные ответы понадобились: запросов ушло меньше, чем ожидалось.',
        );
    }

    /** Фасад, все запросы которого уходят на фейковый сервер. */
    protected function amocrm(): Amocrm
    {
        $amocrm = new Amocrm('example.amocrm.ru', 'token');

        $property = new ReflectionProperty($amocrm, 'apiClient');
        $property->setAccessible(true);
        self::setBaseUrl($property->getValue($amocrm), self::serverUrl());

        return $amocrm;
    }

    /** API-клиент, все запросы которого уходят на фейковый сервер. */
    protected function client(string $token = 'token'): ApiClient
    {
        $client = new ApiClient('example.amocrm.ru', $token);
        self::setBaseUrl($client, self::serverUrl());

        return $client;
    }

    /** Положить в очередь JSON-ответ. */
    protected function respond(array $body, int $status = 200): void
    {
        $this->respondRaw((string) json_encode($body, JSON_UNESCAPED_UNICODE), $status);
    }

    /** Положить в очередь ответ без тела — так amoCRM отвечает на пустую выборку. */
    protected function respondNoContent(): void
    {
        $this->respondRaw('', 204);
    }

    /** Положить в очередь ответ с телом как есть. */
    protected function respondRaw(string $body, int $status = 200): void
    {
        $responses = $this->pendingResponses();
        $responses[] = ['status' => $status, 'body' => $body];

        file_put_contents(self::stateFile('responses.json'), json_encode($responses));
    }

    /** Страница коллекции в формате amoCRM: сущности в `_embedded`, ссылки в `_links`. */
    protected static function page(string $key, array $entities, bool $hasNextPage = false): array
    {
        $links = ['self' => ['href' => "https://example.amocrm.ru/api/v4/$key"]];

        if ($hasNextPage) {
            $links['next'] = ['href' => "https://example.amocrm.ru/api/v4/$key?page=next"];
        }

        return ['_embedded' => [$key => $entities], '_links' => $links];
    }

    /**
     * Запросы, дошедшие до сервера, по порядку.
     *
     * У каждого: method, uri (как пришёл), path, query (раскодированная строка
     * параметров), headers (ключи в нижнем регистре), body (разобранный JSON
     * или null) и rawBody.
     */
    protected function requests(): array
    {
        $requests = [];
        $lines = file(self::stateFile('requests.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines ?: [] as $line) {
            $requests[] = json_decode($line, true);
        }

        return $requests;
    }

    /** Запросы короткими строками: 'GET /api/v4/leads?page=1&limit=250'. */
    protected function requestLog(): array
    {
        $log = [];

        foreach ($this->requests() as $request) {
            $log[] = $request['method'] . ' ' . $request['path']
                . ($request['query'] === '' ? '' : '?' . $request['query']);
        }

        return $log;
    }

    protected function lastRequest(): array
    {
        $requests = $this->requests();

        if ($requests === []) {
            self::fail('До сервера не дошло ни одного запроса.');
        }

        return $requests[count($requests) - 1];
    }

    /** Выполнить действие и вернуть исключение, которое оно бросило. */
    protected static function exceptionFrom(callable $action): Throwable
    {
        try {
            $action();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Ожидалось исключение.');
    }

    /**
     * Сам клиент всегда ходит по https, а тестовый сервер понимает только
     * http, поэтому адрес подменяется в обход конструктора.
     */
    protected static function setBaseUrl(ApiClient $client, string $baseUrl): void
    {
        self::baseUrlProperty()->setValue($client, $baseUrl);
    }

    protected static function baseUrlOf(ApiClient $client): string
    {
        return self::baseUrlProperty()->getValue($client);
    }

    /** Порт, который прямо сейчас никто не слушает. */
    protected static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new RuntimeException('Не удалось найти свободный порт.');
        }

        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private function pendingResponses(): array
    {
        return json_decode((string) file_get_contents(self::stateFile('responses.json')), true) ?: [];
    }

    private static function baseUrlProperty(): ReflectionProperty
    {
        $property = new ReflectionProperty(ApiClient::class, 'baseUrl');
        $property->setAccessible(true);

        return $property;
    }

    private static function serverUrl(): string
    {
        return 'http://127.0.0.1:' . self::$port . '/';
    }

    private static function stateFile(string $name): string
    {
        return self::stateDir() . DIRECTORY_SEPARATOR . $name;
    }

    /** Папка, через которую тесты и сервер обмениваются ответами и запросами. */
    private static function stateDir(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amocrm-fake-' . self::$port;
    }

    private static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }

        self::$port = self::freePort();

        if (!is_dir(self::stateDir()) && !mkdir(self::stateDir())) {
            throw new RuntimeException('Не удалось создать папку ' . self::stateDir() . '.');
        }

        $log = self::stateFile('server.log');
        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/server.php'],
            [['pipe', 'r'], ['file', $log, 'a'], ['file', $log, 'a']],
            $pipes,
        );

        if (!is_resource($server)) {
            throw new RuntimeException('Не удалось запустить фейковый сервер.');
        }

        self::$server = $server;
        register_shutdown_function(static function (): void {
            self::stopServer();
        });

        self::waitForServer();
    }

    private static function waitForServer(): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', self::$port);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }

        throw new RuntimeException('Фейковый сервер не поднялся на порту ' . self::$port . '.');
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;

        foreach (glob(self::stateDir() . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir(self::stateDir())) {
            rmdir(self::stateDir());
        }
    }
}
