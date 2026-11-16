<?php
declare(strict_types=1);

// Serviço de push falso para testes (php -S 127.0.0.1:8766 tests/Integration/push_service_stub.php).
// Grava cada entrega em PUSH_STUB_DIR; endpoints /push/gone-* respondem 410 como uma inscrição extinta.
$directory = getenv('PUSH_STUB_DIR');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!is_string($directory) || !is_dir($directory) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || preg_match('#^/push/([A-Za-z0-9_-]{1,64})$#', $path, $match) !== 1) {
    http_response_code(404);
    return true;
}
$record = [
    'device' => $match[1],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => base64_encode((string)file_get_contents('php://input')),
];
file_put_contents($directory . DIRECTORY_SEPARATOR . $match[1] . '-' . hrtime(true) . '.json', json_encode($record));
http_response_code(str_starts_with($match[1], 'gone') ? 410 : 201);
return true;
