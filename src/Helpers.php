<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../templates/' . $template;
    $content = ob_get_clean();
    require __DIR__ . '/../templates/layout.php';
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function consumeFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Método não permitido.');
    }
    Auth::validateCsrf();
}

function audit(PDO $pdo, ?int $userId, string $action, string $entity, ?int $entityId, string $details = ''): void
{
    $statement = $pdo->prepare(
        'INSERT INTO operation_logs (user_id, action, entity, entity_id, details, happened_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([$userId, $action, $entity, $entityId, $details, Database::now()]);
}

function baseUrl(string $path = ''): string
{
    $configured = rtrim(getenv('PUBLIC_BASE_URL') ?: '', '/');
    if ($configured !== '') {
        return $configured . $path;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $port = (int)($_SERVER['SERVER_PORT'] ?? ($https ? 443 : 80));
    $defaultPort = $https ? 443 : 80;
    $portPart = $port !== $defaultPort ? ':' . $port : '';
    return $scheme . '://' . $host . $portPart . $path;
}
