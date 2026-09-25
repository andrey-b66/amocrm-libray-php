<?php

declare(strict_types=1);

/*
 * Фейковая amoCRM для тестов — роутер встроенного PHP-сервера.
 *
 * Каждый запрос дописывается в requests.jsonl, а в ответ уходит первый ответ
 * из очереди responses.json. Папку с этими файлами сервер делит с
 * FakeAmocrmTestCase: у каждого порта она своя. Если очередь пуста, сервер
 * отвечает 500 и пишет, на какой запрос ответа не нашлось.
 */

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amocrm-fake-' . $_SERVER['SERVER_PORT'];
$uri = $_SERVER['REQUEST_URI'];
$rawBody = (string) file_get_contents('php://input');

$request = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $uri,
    'path' => parse_url($uri, PHP_URL_PATH),
    'query' => urldecode((string) parse_url($uri, PHP_URL_QUERY)),
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => json_decode($rawBody, true),
    'rawBody' => $rawBody,
];

file_put_contents("$dir/requests.jsonl", json_encode($request, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

$responses = json_decode((string) file_get_contents("$dir/responses.json"), true) ?: [];
$response = array_shift($responses);
file_put_contents("$dir/responses.json", json_encode($responses));

header('Content-Type: application/json');

if ($response === null) {
    http_response_code(500);
    echo json_encode(
        ['detail' => "Фейковый сервер: не задан ответ на {$request['method']} $uri"],
        JSON_UNESCAPED_UNICODE,
    );

    return;
}

http_response_code($response['status']);

foreach ($response['headers'] ?? [] as $name => $value) {
    header("$name: $value");
}

echo $response['body'];
