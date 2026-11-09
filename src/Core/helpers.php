<?php
declare(strict_types=1);

// Funções globais usadas pelas views (escape, URLs, formulários, formatação, menu). Todas protegidas por
// function_exists. Contrato completo: docs/ARQUITETURA.md, seção 5.4. Mantenha-as finas: a lógica fica em classes.

use Saa\Core\App;
use Saa\Core\Config;
use Saa\Core\Navigation;
use Saa\Domain\Profile;
use Saa\Security\Permissions;
use Saa\Support\Dates;
use Saa\Support\Labels;

if (!function_exists('e')) {
    /** Escapa um valor para saída HTML (sempre use nas views). */
    function e(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Caminho interno (começa com "/") com query string opcional. Sempre relativo ao domínio: "//host" vira "/host".
     * Valores null da query são omitidos. O resultado ainda deve passar por e() ao ir para um atributo HTML.
     *
     * @param array<string, mixed> $query
     */
    function url(string $path = '/', array $query = []): string
    {
        $path = '/' . ltrim(trim($path), '/');
        if ($query === []) {
            return $path;
        }
        $string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($string === '') {
            return $path;
        }
        return $path . (str_contains($path, '?') ? '&' : '?') . $string;
    }
}

if (!function_exists('absolute_url')) {
    /** URL completa (para e-mails): usa APP_URL; sem ela, o esquema e o host da requisição atual. */
    function absolute_url(string $path = '/', array $query = []): string
    {
        $base = rtrim(Config::string('APP_URL'), '/');
        if ($base === '') {
            $request = App::current()?->request();
            $scheme = $request !== null && $request->isSecure() ? 'https' : 'http';
            $base = $scheme . '://' . ($request?->host() ?? 'localhost');
        }
        return $base . url($path, $query);
    }
}

if (!function_exists('asset')) {
    /** URL de um arquivo de public/assets, com ?v=<mtime> para renovar o cache do navegador. */
    function asset(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }
        $url = '/assets/' . $path;
        if ($path === '' || str_contains($path, '..')) {
            return $url;
        }
        $file = Config::basePath('public/assets/' . $path);
        $time = is_file($file) ? filemtime($file) : false;
        return $time === false ? $url : $url . '?v=' . $time;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        $app = App::current();
        return $app !== null && $app->session()->isStarted() ? $app->csrf()->token() : '';
    }
}

if (!function_exists('csrf_field')) {
    /** <input type="hidden" name="csrf_token" ...>: obrigatório em todo formulário POST. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('old')) {
    /** Valor digitado antes de um erro de validação (formulário re-renderizado com a chave "old"). Escape com e(). */
    function old(string $field, mixed $default = ''): mixed
    {
        return App::current()?->view()->old($field, $default) ?? $default;
    }
}

if (!function_exists('field_error')) {
    /** Mensagem de erro do campo (chave "errors" do formulário em exibição) ou null. Escape com e(). */
    function field_error(string $field): ?string
    {
        return App::current()?->view()->fieldError($field);
    }
}

if (!function_exists('flash_messages')) {
    /**
     * Mensagens flash pendentes (cada uma aparece uma única vez; a leitura as consome).
     *
     * @return list<array{kind: string, message: string}>
     */
    function flash_messages(): array
    {
        return App::current()?->session()->pullFlashes() ?? [];
    }
}

if (!function_exists('current_user')) {
    /**
     * Usuário autenticado da requisição atual (id, name, email, profile) ou null.
     *
     * @return array{id: string, name: string, email: string, profile: string}|null
     */
    function current_user(): ?array
    {
        return App::current()?->request()?->user();
    }
}

if (!function_exists('can')) {
    /** O perfil do usuário atual tem a permissão? (Só esconde interface: o acesso é garantido pelo Kernel.) */
    function can(string $permission): bool
    {
        $user = current_user();
        return $user !== null && Permissions::can($user['profile'], $permission);
    }
}

if (!function_exists('profile_label')) {
    function profile_label(?string $code): string
    {
        return Profile::labelFor($code);
    }
}

if (!function_exists('format_date')) {
    /** d/m/Y (datas de calendário não mudam de fuso). Vazio ou inválido: "—". */
    function format_date(mixed $value): string
    {
        return Dates::formatDate($value);
    }
}

if (!function_exists('format_datetime')) {
    /** d/m/Y H:i no fuso escolar. Vazio ou inválido: "—". */
    function format_datetime(mixed $value): string
    {
        return Dates::formatDateTime($value);
    }
}

if (!function_exists('partial')) {
    /**
     * HTML de views/partials/<nome>.php (devolve o texto; use <?= partial('icon', ['name' => 'home']) ?>).
     *
     * @param array<string, mixed> $data
     */
    function partial(string $name, array $data = []): string
    {
        $app = App::current();
        if ($app === null) {
            throw new RuntimeException('partial() só pode ser usado durante a renderização de uma view.');
        }
        return $app->view()->partial($name, $data);
    }
}

if (!function_exists('label')) {
    /** Rótulo pt-BR de um código de enum. Tipos: shift, profile, attendance_status, justification_status, reason, notification_status, email_status, relationship. */
    function label(string $type, mixed $code): string
    {
        return Labels::label($type, $code);
    }
}

if (!function_exists('badge')) {
    /** HTML <span class="badge badge--tom">Rótulo</span> (já escapado: imprima sem e()). */
    function badge(string $type, mixed $code): string
    {
        return Labels::badge($type, $code);
    }
}

if (!function_exists('nav_items')) {
    /**
     * Itens do menu que o perfil atual pode usar e que já existem como rota GET; o da página atual vem com active=true.
     * Visitantes recebem lista vazia.
     *
     * @return list<array{label: string, path: string, icon: string, permission: string|null, order: int, description: string, active: bool}>
     */
    function nav_items(): array
    {
        $app = App::current();
        $request = $app?->request();
        $user = $request?->user();
        if ($app === null || $request === null || $user === null) {
            return [];
        }
        return Navigation::items($app->navigationConfig(), $user['profile'], $app->router(), $request->path());
    }
}
