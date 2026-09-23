<?php

declare(strict_types=1);

namespace Amocrm\Client;

use Amocrm\Exception\ApiException;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP-клиент amoCRM API v4 поверх cURL.
 *
 * Никаких настроек: домен и долгосрочный токен — всё, что нужно. Ответ
 * возвращается обычным массивом, любая ошибка приходит как ApiException.
 *
 * Упавший запрос не повторяется: HTTP 429, 5xx и обрыв связи приходят
 * вызывающему коду как ApiException. Только он знает, можно ли отправить
 * запись заново и не завести при этом дубль.
 *
 * Запросы уходят по одному, в том порядке, в каком их сделал вызывающий код.
 *
 * Пример: $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50')
 * Пример: $amocrm->raw()->patch('api/v4/leads/' . $leadId, ['price' => 1000])
 */
final class ApiClient
{
    /** Сколько ждать ответа целиком, в секундах, если не задано иное. */
    public const DEFAULT_TIMEOUT = 30;

    /** Сколько ждать соединения с amoCRM, в секундах, если не задано иное. */
    public const DEFAULT_CONNECT_TIMEOUT = 10;

    private string $baseUrl;

    private int $timeout;

    private int $connectTimeout;

    /** @var string[] */
    private array $headers;

    /**
     * Одно соединение на клиент: запросы подряд, например обход страниц,
     * не открывают TLS-соединение с amoCRM каждый раз заново.
     *
     * @var resource|\CurlHandle
     */
    private $curl;

    /**
     * Таймауты задают, сколько ждать ответа целиком и сколько — соединения.
     * Их поднимают для тяжёлых выгрузок и медленных каналов.
     */
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

        // Ноль для cURL означает «ждать без конца», поэтому он не допускается.
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

    /**
     * Выполнить запрос и вернуть разобранный ответ amoCRM.
     *
     * $query — обычная строка параметров, как в адресной строке браузера.
     */
    private function send(string $method, string $endpoint, array $data, string $query): array
    {
        $options = [
            CURLOPT_URL => $this->buildUrl($endpoint, $query),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            // Пустая строка — принять любое сжатие, которое умеет cURL.
            CURLOPT_ENCODING => '',
        ];

        // Тело у записи отправляется всегда, даже пустое: без Content-Length
        // часть серверов отвечает на POST и PUT ошибкой 411.
        if ($method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = $this->encodeBody($data, $method, $endpoint);
        }

        // Настройки прошлого запроса сбрасываются, живое соединение остаётся.
        curl_reset($this->curl);
        curl_setopt_array($this->curl, $options);

        $response = curl_exec($this->curl);

        if ($response === false) {
            // Запрос не дошёл до amoCRM: обрыв связи, таймаут, неизвестный домен.
            throw new ApiException(
                'Не удалось выполнить запрос к amoCRM. ' . curl_error($this->curl),
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

    /**
     * Закодировать строку параметров для адреса запроса.
     *
     * Строка пишется как есть: пробелы, кириллица и квадратные скобки
     * кодируются здесь, а уже закодированное (`%D0%9E`) второй раз не
     * трогается. amoCRM понимает оба вида.
     */
    private static function encodeQuery(string $query): string
    {
        $encoded = preg_replace_callback(
            '/[^A-Za-z0-9_\-.~!$&\'()*+,;=%:@\/?]++|%(?![A-Fa-f0-9]{2})/',
            static fn (array $match): string => rawurlencode($match[0]),
            $query,
        );

        return $encoded ?? $query;
    }

    /** Разобрать ответ amoCRM или бросить ApiException. */
    private function parseResponse(int $statusCode, string $body, string $method, string $endpoint): array
    {
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
        switch ($statusCode) {
            case 400:
                $message = 'amoCRM отклонила данные запроса.';
                break;
            case 401:
                $message = 'Долгосрочный токен amoCRM недействителен или отозван.';
                break;
            case 402:
                $message = 'Аккаунт amoCRM не оплачен или возможность не входит в тариф.';
                break;
            case 403:
                // Тем же кодом amoCRM отвечает на блокировку аккаунта за
                // повторное превышение лимита запросов и на фильтр по IP.
                $message = 'amoCRM отклонила запрос: недостаточно прав или аккаунт заблокирован.';
                break;
            case 404:
                $message = 'Запрошенный ресурс amoCRM не найден.';
                break;
            case 429:
                $message = 'Превышен лимит запросов к amoCRM.';
                break;
            default:
                $message = $statusCode >= 500
                    ? 'Сервис amoCRM временно недоступен.'
                    : "amoCRM вернула HTTP-ошибку $statusCode.";
        }

        $detail = $responseData['detail'] ?? null;

        if (is_string($detail) && trim($detail) !== '') {
            $message .= ' ' . trim($detail);
        }

        return $message;
    }
}
