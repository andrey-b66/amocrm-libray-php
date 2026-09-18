<?php

declare(strict_types=1);

/*
 * Роутер встроенного PHP-сервера для тестов ApiClient.
 *
 * /status/{код} — ответить этим кодом, телом ответа служит тело запроса;
 * /empty        — HTTP 204 без тела;
 * /not-json     — HTTP 200 с HTML вместо JSON;
 * любой другой  — вернуть, каким пришёл запрос: метод, адрес, заголовки, тело.
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$body = file_get_contents('php://input');

if (preg_match('#^/status/(\d{3})$#', $path, $match) === 1) {
    http_response_code((int) $match[1]);
    header('Content-Type: application/json');
    echo $body;

    return;
}

if ($path === '/empty') {
    http_response_code(204);

    return;
}

if ($path === '/not-json') {
    header('Content-Type: text/html');
    echo '<html>Внутренняя ошибка</html>';

    return;
}

header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $uri,
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => $body,
]);
