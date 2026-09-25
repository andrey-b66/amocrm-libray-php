<?php

declare(strict_types=1);

namespace Amocrm\Tests\Fake;

use Amocrm\Client\ApiClient;
use Amocrm\Facade\Amocrm;
use Amocrm\Tests\CatchesExceptions;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Тесты поверх фейковой amoCRM — встроенного PHP-сервера из server.php.
 *
 * Тест кладёт ответы в очередь, сервер отдаёт их по порядку, а тест проверяет
 * дошедшие запросы. Неизрасходованный ответ после теста — ошибка: запросов ушло
 * меньше, чем ожидалось. Сервер один на прогон и гаснет вместе с PHPUnit.
 *
 * Пример: $this->respond(self::page('leads', [['id' => 1]]));
 *         $leads = $this->amocrm()->leads()->find();
 *         self::assertSame(['GET /api/v4/leads?page=1&limit=250'], $this->requestLog());
 *
 * @requires extension curl
 */
abstract class FakeAmocrmTestCase extends TestCase
{
    use CatchesExceptions;

    /** @var resource|null */
    private static $server = null;

    private static int $port = 0;

    /** Поднять сервер при первом тесте и очистить очередь ответов и журнал запросов. */
    protected function setUp(): void
    {
        parent::setUp();

        self::startServer();
        file_put_contents(self::stateFile('responses.json'), '[]');
        file_put_contents(self::stateFile('requests.jsonl'), '');
    }

    /** Все заготовленные ответы должны уйти по назначению. */
    protected function assertPostConditions(): void
    {
        self::assertSame(
            [],
            $this->pendingResponses(),
            'Не все заготовленные ответы понадобились: запросов ушло меньше, чем ожидалось.',
        );
    }

    /** Фасад с долгосрочным токеном, все запросы которого уходят на фейковый сервер. */
    protected function amocrm(): Amocrm
    {
        return self::onFakeServer(new Amocrm('example.amocrm.ru', 'token'));
    }

    /** API-клиент с долгосрочным токеном, все запросы которого уходят на фейковый сервер. */
    protected function client(string $token = 'token'): ApiClient
    {
        return self::toFakeServer(new ApiClient('example.amocrm.ru', $token));
    }

    /** Направить все запросы фасада на фейковый сервер. */
    protected static function onFakeServer(Amocrm $amocrm): Amocrm
    {
        self::toFakeServer(self::privateProperty($amocrm, 'apiClient'));

        return $amocrm;
    }

    /** Направить запросы клиента на фейковый сервер. */
    protected static function toFakeServer(ApiClient $client): ApiClient
    {
        self::setBaseUrl($client, self::serverUrl());

        return $client;
    }

    /**
     * Значение приватного свойства объекта.
     *
     * @return mixed
     */
    protected static function privateProperty(object $object, string $name)
    {
        $property = new ReflectionProperty($object, $name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    /** Положить в очередь JSON-ответ. */
    protected function respond(array $body, int $status = 200, array $headers = []): void
    {
        $this->respondRaw((string) json_encode($body, JSON_UNESCAPED_UNICODE), $status, $headers);
    }

    /** Положить в очередь ответ без тела — так amoCRM отвечает на пустую выборку. */
    protected function respondNoContent(): void
    {
        $this->respondRaw('', 204);
    }

    /** Положить в очередь ответ с телом как есть; $headers — заголовки ответа, имя => значение. */
    protected function respondRaw(string $body, int $status = 200, array $headers = []): void
    {
        $responses = $this->pendingResponses();
        $responses[] = ['status' => $status, 'body' => $body, 'headers' => $headers];

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
     * Запросы, дошедшие до сервера, по порядку. У каждого: method, uri (как
     * пришёл), path, query (раскодированный), headers (ключи в нижнем регистре),
     * body (разобранный JSON или null) и rawBody.
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

    /** Последний дошедший запрос; тест падает, если запросов не было. */
    protected function lastRequest(): array
    {
        $requests = $this->requests();

        if ($requests === []) {
            self::fail('До сервера не дошло ни одного запроса.');
        }

        return $requests[count($requests) - 1];
    }

    /** Подменить адрес клиента в обход конструктора: тот всегда ставит https, а сервер понимает только http. */
    protected static function setBaseUrl(ApiClient $client, string $baseUrl): void
    {
        self::baseUrlProperty()->setValue($client, $baseUrl);
    }

    /** Адрес, на который ходит клиент. */
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

    /** Ответы, которые ещё ждут своего запроса. */
    private function pendingResponses(): array
    {
        return json_decode((string) file_get_contents(self::stateFile('responses.json')), true) ?: [];
    }

    /** Доступ к приватному ApiClient::$baseUrl. */
    private static function baseUrlProperty(): ReflectionProperty
    {
        $property = new ReflectionProperty(ApiClient::class, 'baseUrl');
        $property->setAccessible(true);

        return $property;
    }

    /** Адрес фейкового сервера. */
    private static function serverUrl(): string
    {
        return 'http://127.0.0.1:' . self::$port . '/';
    }

    /** Путь к файлу в папке, общей с сервером. */
    private static function stateFile(string $name): string
    {
        return self::stateDir() . DIRECTORY_SEPARATOR . $name;
    }

    /** Папка, через которую тесты и сервер обмениваются ответами и запросами. */
    private static function stateDir(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amocrm-fake-' . self::$port;
    }

    /** Запустить сервер на свободном порту, если он ещё не запущен. */
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

    /** Ждать до 5 секунд, пока сервер не начнёт принимать соединения. */
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

    /** Остановить сервер и удалить папку состояния. */
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
