<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Telefone guardado só com dígitos (10 ou 11) para exibição: (11) 91234-5678. */
function formatPhone(?string $digits): string
{
    if ($digits === null || preg_match('/^[0-9]{10,11}$/', $digits) !== 1) {
        return (string)$digits;
    }
    $split = strlen($digits) - 4;
    return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, $split - 2) . '-' . substr($digits, $split);
}

/** Página inicial da equipe: um painel com os atalhos do perfil. */
function staffHomePath(): string
{
    return '/inicio';
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
