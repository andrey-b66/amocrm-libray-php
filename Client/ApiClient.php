<?php

declare(strict_types=1);

namespace Amocrm\Client;

use Amocrm\Exception\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
 * Запросы уходят по одному, в том порядке, в каком их сделал вызывающий код.
 *
 * Пример: $amocrm->raw()->get('api/v4/events', 'filter[entity][0]=lead&limit=50')
 * Пример: $amocrm->raw()->delete('api/v4/leads/notes/' . $noteId)
 */
final class ApiClient
{
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
            'timeout' => 30,
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

    /**
     * Выполнить запрос и вернуть разобранный ответ amoCRM.
     *
     * $query — обычная строка параметров, как в адресной строке браузера.
     */
    private function send(string $method, string $endpoint, array $data, string $query): array
    {
        $body = null;

        if ($data !== []) {
            $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $request = new Request($method, $this->buildUrl($endpoint, $query), [], $body);

        try {
            $response = $this->http->send($request);
        } catch (GuzzleException $exception) {
            // Запрос не дошёл до amoCRM: обрыв связи, таймаут, неизвестный домен.
            throw new ApiException(
                'Не удалось выполнить запрос к amoCRM. ' . $exception->getMessage(),
                0,
                $method,
                $endpoint,
            );
        }

        return $this->parseResponse($response, $method, $endpoint);
    }

    /**
     * Собрать стек обработчиков Guzzle с повторами.
     *
     * Повтор живёт на уровне одного запроса: пауза перед повторной попыткой
     * останавливает вызывающий код до тех пор, пока запрос не завершится.
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

                // Ответа нет вовсе — значит обрыв связи или таймаут. Такой
                // случай обозначается нулём: настоящего кода у него нет.
                $statusCode = 0;

                if ($response !== null) {
                    $statusCode = $response->getStatusCode();
                }

                return self::isRetryable($request->getMethod(), $statusCode);
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

    /** Собрать полный адрес запроса из эндпоинта и строки параметров. */
    private function buildUrl(string $endpoint, string $query): string
    {
        $url = $this->baseUrl . ltrim(trim($endpoint), '/');
        $query = trim(trim($query), '?&');

        if ($query === '') {
            return $url;
        }

        // Строка параметров пишется как есть: пробелы, кириллицу и квадратные
        // скобки Guzzle закодирует сам при сборке запроса, а уже закодированное
        // (`%D0%9E`) второй раз не тронет. amoCRM понимает оба вида.
        return $url . '?' . $query;
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
