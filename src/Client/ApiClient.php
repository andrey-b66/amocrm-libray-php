<?php

declare(strict_types=1);

namespace Amocrm\Client;

use Amocrm\Exception\ApiException;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP-клиент amoCRM API v4 поверх cURL.
 *
 * Возвращает разобранный ответ массивом, любую ошибку бросает как ApiException.
 * Запросы не повторяются: повторять ли запись после сбоя, решает вызывающий код.
 *
 * Пример: $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50')
 * Пример: $amocrm->raw()->patch('api/v4/leads/' . $leadId, ['price' => 1000])
 */
final class ApiClient
{
    /** Сколько ждать ответа целиком по умолчанию, в секундах. */
    public const DEFAULT_TIMEOUT = 30;

    /** Сколько ждать соединения по умолчанию, в секундах. */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    /** Текст ошибки по HTTP-коду; остальные коды описывает errorMessage(). */
    private const ERROR_MESSAGES = [
        400 => 'amoCRM отклонила данные запроса.',
        401 => 'Токен amoCRM истёк, недействителен или отозван.',
        402 => 'Аккаунт amoCRM не оплачен или возможность не входит в тариф.',
        403 => 'amoCRM отклонила запрос: недостаточно прав или аккаунт заблокирован.',
        404 => 'Запрошенный ресурс amoCRM не найден.',
        429 => 'Превышен лимит запросов к amoCRM.',
    ];

    /** Адрес аккаунта со слешем на конце: `https://example.amocrm.ru/`. */
    private string $baseUrl;

    /** Сколько ждать ответа целиком, в секундах. */
    private int $timeout;

    /** Сколько ждать соединения, в секундах. */
    private int $connectTimeout;

    /** @var string[] заголовки каждого запроса */
    private array $headers;

    /** Заголовок `X-Request-Id` последнего ответа; пустая строка, если его не было. */
    private string $lastRequestId = '';

    /**
     * Один хэндл на клиент: запросы подряд не открывают соединение заново.
     *
     * @var resource|\CurlHandle
     */
    private $curl;

    /** Домен — в любом виде: со схемой, слешем, в любом регистре. Таймауты — в секундах. */
    public function __construct(
        string $domain,
        string $longLivedToken,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT
    ) {
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

        // Ноль для cURL значит «ждать без конца».
        if ($timeout < 1 || $connectTimeout < 1) {
            throw new InvalidArgumentException('Таймауты должны быть положительными, в секундах.');
        }

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('Не удалось инициализировать cURL.');
        }

        $this->baseUrl = "https://$domain/";
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->curl = $curl;
        $this->headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: integrat-amocrm',
        ];
    }

    /** GET-запрос. $query — строка параметров, как в адресной строке браузера. */
    public function get(string $endpoint, string $query = ''): array
    {
        return $this->send('GET', $endpoint, [], $query);
    }

    /** POST-запрос: $data уходит телом в JSON. */
    public function post(string $endpoint, array $data = []): array
    {
        return $this->send('POST', $endpoint, $data, '');
    }

    /** PATCH-запрос: $data уходит телом в JSON. */
    public function patch(string $endpoint, array $data = []): array
    {
        return $this->send('PATCH', $endpoint, $data, '');
    }

    /** ID последнего запроса из заголовка `X-Request-Id`, например для логов; пустая строка, если его не было. */
    public function lastRequestId(): string
    {
        return $this->lastRequestId;
    }

    /** Выполнить запрос и вернуть разобранный ответ amoCRM. */
    private function send(string $method, string $endpoint, array $data, string $query): array
    {
        $requestId = '';

        $options = [
            CURLOPT_URL => $this->buildUrl($endpoint, $query),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            // Пустая строка — принять любое сжатие, которое умеет cURL.
            CURLOPT_ENCODING => '',
            // Статическое замыкание, чтобы хэндл не держал ссылку на клиент.
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$requestId): int {
                if (stripos($line, 'X-Request-Id:') === 0) {
                    $requestId = trim(substr($line, strlen('X-Request-Id:')));
                }

                return strlen($line);
            },
        ];

        // Тело шлётся и пустым: без Content-Length часть серверов отвечает 411.
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $this->encodeBody($data, $method, $endpoint);
        }

        // Настройки прошлого запроса сбрасываются, соединение остаётся.
        curl_reset($this->curl);
        curl_setopt_array($this->curl, $options);

        $response = curl_exec($this->curl);
        $this->lastRequestId = $requestId;

        if ($response === false) {
            // Код cURL пишется всегда: описание бывает пустым.
            $error = curl_error($this->curl);

            throw new ApiException(
                'Не удалось выполнить запрос к amoCRM. '
                . ($error === '' ? 'Причина неизвестна.' : $error)
                . ' Код cURL: ' . curl_errno($this->curl) . '.',
                0,
                $method,
                $endpoint,
            );
        }

        $statusCode = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);

        return $this->parseResponse($statusCode, (string) $response, $method, $endpoint);
    }

    /** Закодировать данные запроса в JSON; без данных тело пустое. */
    private function encodeBody(array $data, string $method, string $endpoint): string
    {
        if ($data === []) {
            return '';
        }

        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            throw new ApiException(
                'Не удалось закодировать данные запроса в JSON. ' . json_last_error_msg(),
                0,
                $method,
                $endpoint,
            );
        }

        return $body;
    }

    /** Собрать полный адрес запроса из эндпоинта и строки параметров. */
    private function buildUrl(string $endpoint, string $query): string
    {
        $url = $this->baseUrl . ltrim(trim($endpoint), '/');
        $query = trim(trim($query), '?&');

        if ($query === '') {
            return $url;
        }

        return $url . '?' . self::encodeQuery($query);
    }

    /** Закодировать пробелы, кириллицу и скобки в строке параметров; уже закодированное не трогать. */
    private static function encodeQuery(string $query): string
    {
        $encoded = preg_replace_callback(
            '/[^A-Za-z0-9_\-.~!$&\'()*+,;=%:@\/?]++|%(?![A-Fa-f0-9]{2})/',
            static fn (array $match): string => rawurlencode($match[0]),
            $query,
        );

        return $encoded ?? $query;
    }

    /** Разобрать ответ amoCRM или бросить ApiException; HTTP 204 — пустой ответ, не ошибка. */
    private function parseResponse(int $statusCode, string $body, string $method, string $endpoint): array
    {
        $responseData = json_decode($body, true);

        if (!is_array($responseData)) {
            $responseData = [];
        }

        if ($statusCode >= 200 && $statusCode < 300) {
            return $responseData;
        }

        throw new ApiException(
            $this->errorMessage($statusCode, $responseData),
            $statusCode,
            $method,
            $endpoint,
            $responseData,
            $this->lastRequestId,
        );
    }

    /** Текст ошибки по HTTP-коду, дополненный пояснением `detail` из ответа amoCRM. */
    private function errorMessage(int $statusCode, array $responseData): string
    {
        $message = self::ERROR_MESSAGES[$statusCode]
            ?? ($statusCode >= 500 ? 'Сервис amoCRM временно недоступен.' : "amoCRM вернула HTTP-ошибку $statusCode.");

        $detail = $responseData['detail'] ?? null;

        if (is_string($detail) && trim($detail) !== '') {
            $message .= ' ' . trim($detail);
        }

        return $message;
    }
}
