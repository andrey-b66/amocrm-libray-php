<?php

declare(strict_types=1);

namespace Amocrm\Client;

use Amocrm\Exception\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * HTTP-клиент amoCRM API v4 поверх Guzzle.
 *
 * Никаких настроек: домен и долгосрочный токен — всё, что нужно. Ответ
 * возвращается обычным массивом, любая ошибка приходит как ApiException.
 *
 * Запрос, упавший по временной причине, повторяется сам — до шести раз, с
 * паузой, которая удваивается: 1, 2, 4, 8, 16, 32 секунды. Столько ожидания
 * нужно потому, что превышенный лимит запросов amoCRM держит не мгновение.
 *
 * Что считается временным, зависит от метода. HTTP 429 повторяется всегда:
 * amoCRM отклоняет такой запрос целиком и записать ничего не успевает. Обрыв
 * связи и ошибки 5xx повторяются только у GET — у записи ответ мог потеряться
 * уже после того, как amoCRM всё создала, и повтор завёл бы вторую копию.
 *
 * Методы на -Many шлют пачку запросов: getMany() ходит по одному эндпоинту,
 * остальные принимают запросы с любыми эндпоинтами. Передавать можно сколько
 * угодно — в полёте всё равно держится не больше MAX_REQUESTS_AT_ONCE, а
 * освободившееся место сразу занимает следующий запрос. Ошибка приходит не
 * исключением, а значением в результате: иначе после упавшего запроса было бы
 * не понять, что из пачки успело записаться.
 *
 * Пример: $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50')
 * Пример: $amocrm->raw()->delete('api/v4/leads/notes/' . $noteId)
 * Пример: $amocrm->raw()->getMany('api/v4/leads', ['page=1&limit=250', 'page=2&limit=250'])
 * Пример: $amocrm->raw()->postMany([['endpoint' => 'api/v4/leads/10/link', 'data' => [$link]]])
 */
final class ApiClient
{
    /** Лимит amoCRM: больше семи запросов в секунду на аккаунт не принимается. */
    public const MAX_REQUESTS_AT_ONCE = 7;

    /** Сколько раз повторять запрос, упавший по временной причине. */
    private const RETRY_ATTEMPTS = 6;

    /** Пауза перед первым повтором в миллисекундах; дальше удваивается. */
    private const RETRY_BASE_DELAY_MS = 1000;

    private string $baseUrl;
    private Client $http;

    public function __construct(string $domain, string $longLivedToken)
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = trim($domain, '/');
        $token = trim($longLivedToken);

        if ($domain === '') {
            throw new InvalidArgumentException('Указан некорректный домен аккаунта amoCRM.');
        }

        if ($token === '') {
            throw new InvalidArgumentException('Долгосрочный токен amoCRM не должен быть пустым.');
        }

        $this->baseUrl = "https://$domain/";
        $this->http = new Client([
            'handler' => $this->handlerStack(),
            // Без таймаута зависший ответ amoCRM держал бы скрипт до лимита выполнения.
            'timeout' => 30,
            // Коды ошибок разбираем сами: в теле ответа amoCRM лежит `detail`,
            // а исключение Guzzle его бы спрятало.
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function get(string $endpoint, string $query = ''): array
    {
        return $this->send('GET', $endpoint, [], $query);
    }

    public function post(string $endpoint, array $data = [], string $query = ''): array
    {
        return $this->send('POST', $endpoint, $data, $query);
    }

    public function patch(string $endpoint, array $data = [], string $query = ''): array
    {
        return $this->send('PATCH', $endpoint, $data, $query);
    }

    public function put(string $endpoint, array $data = [], string $query = ''): array
    {
        return $this->send('PUT', $endpoint, $data, $query);
    }

    public function delete(string $endpoint, array $data = [], string $query = ''): array
    {
        return $this->send('DELETE', $endpoint, $data, $query);
    }

    /**
     * Выполнить несколько GET-запросов к одному эндпоинту одновременно.
     *
     * Ключи входного массива сохраняются, порядок результата — тот же, что у
     * переданных запросов.
     *
     * Пример: getMany('api/v4/leads', [1 => 'page=1&limit=250', 2 => 'page=2&limit=250'])
     *
     * @param array<array-key, string> $queries строки параметров, как в адресной строке браузера
     * @return array<array-key, array|ApiException> ответы amoCRM под теми же ключами
     */
    public function getMany(string $endpoint, array $queries): array
    {
        $requests = [];

        foreach ($queries as $key => $query) {
            $requests[$key] = ['endpoint' => $endpoint, 'query' => $query];
        }

        return $this->sendMany('GET', $requests);
    }

    /**
     * Выполнить несколько POST-запросов одновременно.
     *
     * Нужен там, где эндпоинты разные: привязать товар к семи сделкам, создать
     * примечания в разных сущностях. Однотипные записи в один и тот же эндпоинт
     * amoCRM принимает пачкой в теле обычного post() — 250 сделок одним
     * запросом вместо семи параллельных, и лимит запросов не тратится.
     *
     * Пример: postMany([
     *     10 => ['endpoint' => 'api/v4/leads/10/link', 'data' => [$link]],
     *     20 => ['endpoint' => 'api/v4/leads/20/link', 'data' => [$link]],
     * ])
     *
     * @param array<array-key, array{endpoint: string, data?: array, query?: string}> $requests
     * @return array<array-key, array|ApiException> ответы amoCRM под теми же ключами
     */
    public function postMany(array $requests): array
    {
        return $this->sendMany('POST', $requests);
    }

    /**
     * Выполнить несколько PATCH-запросов одновременно.
     *
     * Пример: patchMany([
     *     10 => ['endpoint' => 'api/v4/leads/10', 'data' => ['price' => 1000]],
     *     20 => ['endpoint' => 'api/v4/leads/20', 'data' => ['price' => 2000]],
     * ])
     *
     * @param array<array-key, array{endpoint: string, data?: array, query?: string}> $requests
     * @return array<array-key, array|ApiException> ответы amoCRM под теми же ключами
     */
    public function patchMany(array $requests): array
    {
        return $this->sendMany('PATCH', $requests);
    }

    /**
     * Выполнить несколько PUT-запросов одновременно.
     *
     * @param array<array-key, array{endpoint: string, data?: array, query?: string}> $requests
     * @return array<array-key, array|ApiException> ответы amoCRM под теми же ключами
     */
    public function putMany(array $requests): array
    {
        return $this->sendMany('PUT', $requests);
    }

    /**
     * Выполнить несколько DELETE-запросов одновременно.
     *
     * @param array<array-key, array{endpoint: string, data?: array, query?: string}> $requests
     * @return array<array-key, array|ApiException> ответы amoCRM под теми же ключами
     */
    public function deleteMany(array $requests): array
    {
        return $this->sendMany('DELETE', $requests);
    }

    /**
     * Выполнить пачку запросов одним HTTP-методом.
     *
     * В полёте держится не больше MAX_REQUESTS_AT_ONCE запросов: столько
     * amoCRM разрешает на аккаунт. Как только один ответ пришёл, его место
     * занимает следующий запрос очереди, поэтому пачка любой длины идёт без
     * простоя — ждать всю семёрку, чтобы отправить восьмой, не приходится.
     *
     * Упавший запрос не срывает остальные: под его ключом в результате лежит
     * ApiException вместо ответа — по нему видно, что именно не прошло, а что
     * записалось. Повторы делаются на уровне каждого запроса отдельно, ответы
     * соседей при этом не теряются и второй раз не отправляются.
     *
     * @param array<array-key, array{endpoint: string, data?: array, query?: string}> $requests
     * @return array<array-key, array|ApiException>
     */
    private function sendMany(string $method, array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $endpoints = [];
        $httpRequests = [];

        foreach ($requests as $key => $request) {
            $endpoint = $request['endpoint'] ?? null;

            if (!is_string($endpoint) || trim($endpoint) === '') {
                throw new InvalidArgumentException(
                    'В параллельном запросе ' . $key . ' не указан эндпоинт amoCRM.',
                );
            }

            $endpoints[$key] = $endpoint;
            $httpRequests[$key] = $this->buildRequest(
                $method,
                $endpoint,
                (array) ($request['data'] ?? []),
                (string) ($request['query'] ?? ''),
            );
        }

        $responses = [];

        $pool = new Pool($this->http, $httpRequests, [
            'concurrency' => self::MAX_REQUESTS_AT_ONCE,
            'fulfilled' => function (ResponseInterface $response, mixed $key) use (
                &$responses,
                $method,
                $endpoints,
            ): void {
                try {
                    $responses[$key] = $this->parseResponse($response, $method, $endpoints[$key]);
                } catch (ApiException $exception) {
                    // Ошибка одного запроса не отменяет уже полученные ответы.
                    $responses[$key] = $exception;
                }
            },
            'rejected' => function (Throwable $reason, mixed $key) use (
                &$responses,
                $method,
                $endpoints,
            ): void {
                $responses[$key] = $this->connectionFailed($reason, $method, $endpoints[$key]);
            },
        ]);

        $pool->promise()->wait();

        // Порядок результата — тот же, что у переданных запросов.
        $ordered = [];

        foreach (array_keys($requests) as $key) {
            $ordered[$key] = $responses[$key];
        }

        return $ordered;
    }

    /**
     * Выполнить запрос и вернуть разобранный ответ amoCRM.
     *
     * $query — обычная строка параметров, как в адресной строке браузера.
     */
    private function send(string $method, string $endpoint, array $data, string $query): array
    {
        $request = $this->buildRequest($method, $endpoint, $data, $query);

        try {
            $response = $this->http->send($request);
        } catch (GuzzleException $exception) {
            throw $this->connectionFailed($exception, $method, $endpoint);
        }

        return $this->parseResponse($response, $method, $endpoint);
    }

    /**
     * Собрать стек обработчиков Guzzle с повторами.
     *
     * Повтор живёт на уровне одного запроса, поэтому одинаково работает и в
     * обычном вызове, и внутри пачки. В пачке пауза перед повтором не
     * останавливает соседние запросы: Guzzle откладывает такой запрос и
     * возвращается к нему сам.
     */
    private function handlerStack(): HandlerStack
    {
        $stack = HandlerStack::create();

        $stack->push(Middleware::retry(
            static function (
                int $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
            ): bool {
                if ($retries >= self::RETRY_ATTEMPTS) {
                    return false;
                }

                // Ответа нет вовсе — значит обрыв связи или таймаут.
                return self::isRetryable($request->getMethod(), $response?->getStatusCode() ?? 0);
            },
            // Guzzle нумерует повторы с единицы: 1, 2, 4, 8, 16, 32 секунды.
            static fn (int $retries): int => self::RETRY_BASE_DELAY_MS * (2 ** ($retries - 1)),
        ));

        return $stack;
    }

    /**
     * Можно ли повторить запрос, упавший с таким кодом.
     *
     * HTTP 429 повторяется у любого метода: amoCRM отклоняет такой запрос
     * целиком, записать ничего не успевает, и повтор ничего не задваивает.
     *
     * Обрыв связи (код 0) и ошибки 5xx повторяются только у GET. У записи по
     * ним не видно, дошла она или нет: ответ мог потеряться уже после того, как
     * amoCRM всё создала, и повтор завёл бы вторую копию. Такие запросы
     * отправляет заново вызывающий код — он один знает, чем это грозит.
     */
    private static function isRetryable(string $method, int $statusCode): bool
    {
        if ($statusCode === 429) {
            return true;
        }

        return $method === 'GET' && ($statusCode === 0 || $statusCode >= 500);
    }

    /** Собрать запрос к amoCRM: адрес, заголовки от клиента и тело в JSON. */
    private function buildRequest(string $method, string $endpoint, array $data, string $query): Request
    {
        $body = $data === []
            ? null
            : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Request($method, $this->buildUrl($endpoint, $query), [], $body);
    }

    /** Собрать полный адрес запроса из эндпоинта и строки параметров. */
    private function buildUrl(string $endpoint, string $query): string
    {
        $url = $this->baseUrl . ltrim(trim($endpoint), '/');
        $query = trim(trim($query), '?&');

        if ($query !== '') {
            // Пробелы и кириллицу кодируем: сырыми в URL их слать нельзя.
            // Остальное — `&`, `=`, `[`, `]` — остаётся как написали; квадратные
            // скобки Guzzle дальше закодирует сам, amoCRM понимает оба вида.
            $url .= '?' . preg_replace_callback(
                '/[^\x21-\x7e]/',
                static fn (array $match): string => rawurlencode($match[0]),
                $query,
            );
        }

        return $url;
    }

    /** Запрос не дошёл до amoCRM: обрыв связи, таймаут, неизвестный домен. */
    private function connectionFailed(Throwable $reason, string $method, string $endpoint): ApiException
    {
        return new ApiException(
            'Не удалось выполнить запрос к amoCRM. ' . $reason->getMessage(),
            0,
            $method,
            $endpoint,
        );
    }

    /** Разобрать ответ amoCRM или бросить ApiException. */
    private function parseResponse(ResponseInterface $response, string $method, string $endpoint): array
    {
        $statusCode = $response->getStatusCode();
        $responseData = json_decode((string) $response->getBody(), true);

        if (!is_array($responseData)) {
            $responseData = [];
        }

        // HTTP 204 «нет содержимого» — не ошибка, просто пустой ответ.
        if ($statusCode >= 200 && $statusCode < 300) {
            return $responseData;
        }

        throw new ApiException(
            $this->errorMessage($statusCode, $responseData),
            $statusCode,
            $method,
            $endpoint,
            $responseData,
        );
    }

    private function errorMessage(int $statusCode, array $responseData): string
    {
        $message = match ($statusCode) {
            400 => 'amoCRM отклонила данные запроса.',
            401 => 'Долгосрочный токен amoCRM недействителен или отозван.',
            403 => 'Недостаточно прав для выполнения запроса к amoCRM.',
            404 => 'Запрошенный ресурс amoCRM не найден.',
            429 => 'Превышен лимит запросов к amoCRM.',
            default => $statusCode >= 500
                ? 'Сервис amoCRM временно недоступен.'
                : "amoCRM вернула HTTP-ошибку $statusCode.",
        };

        $detail = $responseData['detail'] ?? null;

        if (is_string($detail) && trim($detail) !== '') {
            $message .= ' ' . trim($detail);
        }

        return $message;
    }
}
