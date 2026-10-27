<?php
declare(strict_types=1);

final class Auth
{
    public static function requireLogin(): void
    {
        if (empty($_SESSION['user_id'])) {
            self::redirect('/login');
        }
    }

    public static function requireRole(array $profiles): void
    {
        self::requireLogin();
        if (!in_array($_SESSION['profile'] ?? '', $profiles, true)) {
            http_response_code(403);
            render('error.php', ['title' => 'Acesso negado', 'message' => 'Seu perfil não tem acesso a esta página.']);
            exit;
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function safeNext(string $value): ?string
    {
        if ($value === '' || str_contains($value, "\r") || str_contains($value, "\n")) {
            return null;
        }
        $parts = parse_url($value);
        if (
            $parts === false
            || isset($parts['scheme'])
            || isset($parts['host'])
            || isset($parts['user'])
            || !isset($parts['path'])
            || !str_starts_with($parts['path'], '/')
            || str_starts_with($parts['path'], '//')
        ) {
            return null;
        }
        return $value;
    }

    public static function validateCsrf(): void
    {
        $submitted = $_POST['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if (!is_string($submitted) || $expected === '' || !hash_equals($expected, $submitted)) {
            http_response_code(400);
            render('error.php', [
                'title' => 'Solicitação inválida',
                'message' => 'A sessão expirou ou o formulário está desatualizado. Volte e tente novamente.',
            ]);
            exit;
        }
    }

    public static function redirect(string $path): never
    {
        header('Location: ' . $path, true, 303);
        exit;
    }
}
