<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método não permitido.');
}
if ($method === 'POST') {
    Auth::validateCsrf();
}

function notFound(): never
{
    http_response_code(404);
    render('error.php', ['title' => 'Página não encontrada', 'message' => 'O conteúdo solicitado não foi encontrado.']);
    exit;
}

try {
    require dirname(__DIR__) . '/src/mongo_staff_web.php';
} catch (Throwable $error) {
    error_log((string)$error);
    http_response_code(500);
    render('error.php', [
        'title' => 'Erro interno',
        'message' => 'Não foi possível concluir a operação. O erro foi registrado para análise.',
    ]);
}
