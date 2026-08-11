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
 * Пример: $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50')
 * Пример: $amocrm->raw()->delete('api/v4/leads/notes/' . $noteId)
 */
final class ApiClient
{
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
     * Выполнить запрос и вернуть разобранный ответ amoCRM.
     *
     * $query — обычная строка параметров, как в адресной строке браузера.
     */
    private function send(string $method, string $endpoint, array $data, string $query): array
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

        $curl = curl_init();
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

        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new ApiException(
                'Не удалось выполнить запрос к amoCRM. ' . $error,
                0,
                $method,
                $endpoint,
            );
        }

        $responseData = json_decode((string) $body, true);

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
