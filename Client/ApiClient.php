<?php

declare(strict_types=1);

namespace Amocrm\Client;

use Amocrm\Exception\ApiException;
use InvalidArgumentException;

/**
 * Минимальный HTTP-клиент amoCRM API v4 на обычном cURL.
 *
 * Никаких настроек: домен и долгосрочный токен — всё, что нужно. Один вызов
 * метода — один запрос с таймаутом 30 секунд, без повторных попыток. Ответ
 * возвращается обычным массивом, любая ошибка приходит как ApiException.
 *
 * Методы на -Many шлют пачку запросов разом, до семи за раз: getMany() ходит по
 * одному эндпоинту, остальные принимают запросы с любыми эндпоинтами. Там
 * ошибка приходит не исключением, а значением в результате — иначе после
 * упавшего запроса было бы не понять, что из пачки успело записаться.
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

    private string $baseUrl;
    private string $token;

    public function __construct(string $domain, string $longLivedToken)
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = trim($domain, '/');
        $this->token = trim($longLivedToken);

        if ($domain === '') {
            throw new InvalidArgumentException('Указан некорректный домен аккаунта amoCRM.');
        }

        if ($this->token === '') {
            throw new InvalidArgumentException('Долгосрочный токен amoCRM не должен быть пустым.');
        }

        $this->baseUrl = "https://$domain/";
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
     * Сколько запросов передали, столько и уйдёт разом: вызов занимает столько
     * времени, сколько самый долгий ответ. Ключи входного массива сохраняются,
     * порядок результата — тот же, что у переданных запросов.
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
     * Выполнить пачку запросов одним HTTP-методом одновременно.
     *
     * Больше MAX_REQUESTS_AT_ONCE запросов за раз не принимается: amoCRM
     * разрешает не больше семи в секунду на аккаунт. Повторных попыток нет.
     *
     * Упавший запрос не срывает остальные: под его ключом в результате лежит
     * ApiException вместо ответа — по нему видно, что именно не прошло, а что
     * записалось. Исключение бросается, только если не задалась вся пачка.
     *
     * @param array<array-key, array{endpoint: string, data?: array, query?: string}> $requests
     * @return array<array-key, array|ApiException>
     */
    private function sendMany(string $method, array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        if (count($requests) > self::MAX_REQUESTS_AT_ONCE) {
            throw new InvalidArgumentException(
                'За раз amoCRM принимает не больше ' . self::MAX_REQUESTS_AT_ONCE
                . ' запросов, передано ' . count($requests) . '.',
            );
        }

        $multiHandle = curl_multi_init();
        $handles = [];
        $endpoints = [];

        foreach ($requests as $key => $request) {
            $endpoint = $request['endpoint'] ?? null;

            if (!is_string($endpoint) || trim($endpoint) === '') {
                throw new InvalidArgumentException(
                    'В параллельном запросе ' . $key . ' не указан эндпоинт amoCRM.',
                );
            }

            $endpoints[$key] = $endpoint;

            $curl = curl_init();
            curl_setopt_array($curl, $this->curlOptions(
                $method,
                $this->buildUrl($endpoint, (string) ($request['query'] ?? '')),
                (array) ($request['data'] ?? []),
            ));
            curl_multi_add_handle($multiHandle, $curl);
            $handles[$key] = $curl;
        }

        do {
            $multiStatus = curl_multi_exec($multiHandle, $running);

            // Ждём готовности сокетов, а не крутим цикл вхолостую на процессоре.
            if ($running && curl_multi_select($multiHandle, 1.0) === -1) {
                usleep(1000);
            }
        } while ($running && $multiStatus === CURLM_OK);

        // Итог каждого запроса лежит в очереди сообщений: у отдельного
        // дескриптора после curl_multi_exec() curl_error() бывает пустым даже
        // тогда, когда соединение не состоялось.
        $curlCodes = [];

        while (($message = curl_multi_info_read($multiHandle)) !== false) {
            if ($message['msg'] === CURLMSG_DONE) {
                $curlCodes[spl_object_id($message['handle'])] = (int) $message['result'];
            }
        }

        $rawResponses = [];

        // Дескрипторы закрываем все до единого и только потом разбираем ответы:
        // иначе ошибка на первом же запросе оставила бы остальные висеть.
        foreach ($handles as $key => $curl) {
            $curlCode = $curlCodes[spl_object_id($curl)] ?? CURLE_OK;
            $error = curl_error($curl);

            if ($error === '' && $curlCode !== CURLE_OK) {
                $error = curl_strerror($curlCode) ?? 'Ошибка cURL ' . $curlCode;
            }

            $rawResponses[$key] = [
                'body' => $curlCode === CURLE_OK ? curl_multi_getcontent($curl) : false,
                'statusCode' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
                'error' => $error,
            ];

            curl_multi_remove_handle($multiHandle, $curl);
            curl_close($curl);
        }

        curl_multi_close($multiHandle);

        if ($multiStatus !== CURLM_OK) {
            throw new ApiException(
                'Не удалось выполнить параллельные запросы к amoCRM. ' . curl_multi_strerror($multiStatus),
                0,
                $method,
                implode(', ', array_unique($endpoints)),
            );
        }

        $responses = [];

        foreach ($rawResponses as $key => $rawResponse) {
            try {
                $responses[$key] = $this->parseResponse(
                    $rawResponse['statusCode'],
                    $rawResponse['body'],
                    $rawResponse['error'],
                    $method,
                    $endpoints[$key],
                );
            } catch (ApiException $exception) {
                // Ошибка одного запроса не отменяет уже полученные ответы.
                $responses[$key] = $exception;
            }
        }

        return $responses;
    }

    /**
     * Выполнить запрос и вернуть разобранный ответ amoCRM.
     *
     * $query — обычная строка параметров, как в адресной строке браузера.
     */
    private function send(string $method, string $endpoint, array $data, string $query): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, $this->curlOptions($method, $this->buildUrl($endpoint, $query), $data));

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        return $this->parseResponse($statusCode, $body, $error, $method, $endpoint);
    }

    /** Собрать полный адрес запроса из эндпоинта и строки параметров. */
    private function buildUrl(string $endpoint, string $query): string
    {
        $url = $this->baseUrl . ltrim(trim($endpoint), '/');
        $query = trim(trim($query), '?&');

        if ($query !== '') {
            // Пробелы и кириллицу кодируем: сырыми в URL их слать нельзя.
            // Остальное — `&`, `=`, `[`, `]` — остаётся как написали.
            $url .= '?' . preg_replace_callback(
                '/[^\x21-\x7e]/',
                static fn (array $match): string => rawurlencode($match[0]),
                $query,
            );
        }

        return $url;
    }

    /** Настройки cURL для одного запроса — одинаковые для обычного и параллельного вызова. */
    private function curlOptions(string $method, string $url, array $data): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            // Без таймаута зависший ответ amoCRM держал бы скрипт до лимита выполнения.
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ];

        if ($data !== []) {
            $options[CURLOPT_POSTFIELDS] = json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        }

        return $options;
    }

    /** Разобрать ответ amoCRM или бросить ApiException. */
    private function parseResponse(
        int $statusCode,
        string|bool|null $body,
        string $error,
        string $method,
        string $endpoint,
    ): array {
        if ($body === false || $body === null || $error !== '') {
            throw new ApiException(
                'Не удалось выполнить запрос к amoCRM. ' . $error,
                0,
                $method,
                $endpoint,
            );
        }

        $responseData = json_decode($body, true);

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
