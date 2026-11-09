<?php
declare(strict_types=1);

namespace Saa\Tests\Support;

/**
 * Templates de TESTE (mínimos, seguindo o contrato de views do docs/ARQUITETURA.md) gravados em uma pasta
 * temporária fora do repositório. Permitem testar o Kernel, as views e os helpers sem depender dos templates
 * reais do agente de UI. Também gera arquivos de rotas e de navegação temporários.
 */
final class TempViews
{
    /**
     * Cria a pasta com os templates padrão (sobrescritos/estendidos por $files) e devolve o caminho.
     *
     * @param array<string, string> $files caminho relativo (ex.: "home/index.php") => conteúdo
     */
    public static function create(array $files = []): string
    {
        $directory = self::tempDirectory('saa_views_');
        foreach ([...self::defaults(), ...$files] as $relative => $content) {
            self::write($directory . '/' . $relative, $content);
        }
        return $directory;
    }

    /** Cria uma pasta temporária com arquivos (caminho relativo => conteúdo) e devolve o caminho. */
    public static function directoryWith(array $files, string $prefix = 'saa_tmp_'): string
    {
        $directory = self::tempDirectory($prefix);
        foreach ($files as $relative => $content) {
            self::write($directory . '/' . $relative, $content);
        }
        return $directory;
    }

    public static function remove(string $directory): void
    {
        if ($directory === '' || !is_dir($directory)) {
            return;
        }
        $items = scandir($directory) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            'layouts/app.php' => <<<'PHP'
<?php /** @var string $title @var string $content */ ?>
<!doctype html><html lang="pt-BR"><head><title><?= e($title) ?></title></head><body class="app">
<nav><?php foreach (nav_items() as $item): ?><a class="nav<?= $item['active'] ? ' active' : '' ?>" href="<?= e(url($item['path'])) ?>"><?= e($item['label']) ?></a><?php endforeach; ?></nav>
<span id="user"><?= e(current_user()['name'] ?? '') ?></span>
<?php foreach (flash_messages() as $flash): ?><p class="flash flash-<?= e($flash['kind']) ?>"><?= e($flash['message']) ?></p><?php endforeach; ?>
<?php if (!empty($eyebrow)): ?><small><?= e($eyebrow) ?></small><?php endif; ?>
<h1><?= e($title) ?></h1>
<form method="post" action="/logout"><?= csrf_field() ?><button>Sair</button></form>
<main><?= $content ?></main></body></html>
PHP,
            'layouts/auth.php' => <<<'PHP'
<!doctype html><html lang="pt-BR"><head><title><?= e($title) ?></title></head><body class="auth">
<?php foreach (flash_messages() as $flash): ?><p class="flash flash-<?= e($flash['kind']) ?>"><?= e($flash['message']) ?></p><?php endforeach; ?>
<h1><?= e($title) ?></h1>
<main><?= $content ?></main></body></html>
PHP,
            'layouts/public.php' => <<<'PHP'
<!doctype html><html lang="pt-BR"><head><title><?= e($title) ?></title></head><body class="public">
<h1><?= e($title) ?></h1>
<main><?= $content ?></main></body></html>
PHP,
            'errors/error.php' => <<<'PHP'
<p id="error-status"><?= e((string)$status) ?></p>
<p id="error-title"><?= e($title) ?></p>
<p id="error-message"><?= e($message) ?></p>
PHP,
            'auth/login.php' => <<<'PHP'
<?php if (!empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php foreach ($errors ?? [] as $field => $message): ?><p class="field-error" data-field="<?= e($field) ?>"><?= e($message) ?></p><?php endforeach; ?>
<form method="post" action="/login">
<?= csrf_field() ?>
<input type="hidden" name="next" value="<?= e($next ?? '') ?>">
<input type="email" name="email" value="<?= e(old('email', $email ?? '')) ?>">
<input type="password" name="password">
<button>Entrar</button>
</form>
<a href="/forgot-password">Esqueceu a senha?</a>
PHP,
            'auth/forgot.php' => <<<'PHP'
<p>Procure o administrador do SAA.</p>
PHP,
            'home/index.php' => <<<'PHP'
<p id="greeting"><?= e($greeting) ?>, <?= e($user['name']) ?></p>
<?php foreach ($shortcuts as $shortcut): ?><a class="shortcut" href="<?= e(url($shortcut['path'])) ?>"><?= e($shortcut['label']) ?></a><?php endforeach; ?>
<?php if ($environment !== null): ?><p id="env" data-ok="<?= $environment['ok'] ? '1' : '0' ?>"><?= e((string)$environment['database']) ?>|<?= e((string)$environment['collections']) ?>|<?= e((string)$environment['server_version']) ?>|<?= e((string)$environment['driver_version']) ?></p><?php endif; ?>
<?= can('users.manage') ? '<i>CAN-USERS</i>' : '<i>NO-USERS</i>' ?>
PHP,
            'profile/me.php' => <<<'PHP'
<p id="me-email"><?= e($user['email']) ?></p><p id="me-profile"><?= badge('profile', $user['profile']) ?></p>
<ul><?php foreach ($permissions as $permission): ?><li class="<?= $permission['granted'] ? 'granted' : 'denied' ?>"><?= e($permission['code']) ?></li><?php endforeach; ?></ul>
PHP,
            'profile/matrix.php' => <<<'PHP'
<?php foreach ($profiles as $profile): ?><section data-profile="<?= e($profile['code']) ?>"><?= e($profile['label']) ?> login=<?= $profile['login'] ? 'sim' : 'nao' ?>
<?php foreach ($permissions as $code => $description): ?><span data-perm="<?= e($code) ?>"><?= in_array($code, $grants[$profile['code']] ?? [], true) ? 'X' : '-' ?></span><?php endforeach; ?></section><?php endforeach; ?>
PHP,
            'partials/hello.php' => <<<'PHP'
<b>Olá, <?= e($name ?? 'mundo') ?></b>
PHP,
        ];
    }

    /** Conteúdo de um config/navigation.php de teste (sempre devolve a mesma lista). */
    public static function navigationFile(): string
    {
        return <<<'PHP'
<?php
use Saa\Security\Permissions;

return [
    ['label' => 'Início', 'path' => '/home', 'icon' => 'home', 'permission' => null, 'order' => 10, 'description' => 'Resumo.'],
    ['label' => 'Alunos', 'path' => '/students', 'icon' => 'students', 'permission' => Permissions::REGISTRY_VIEW, 'order' => 50, 'description' => 'Cadastro de alunos.'],
    ['label' => 'Usuários', 'path' => '/users', 'icon' => 'users', 'permission' => Permissions::USERS_MANAGE, 'order' => 80, 'description' => 'Usuários internos.'],
    ['label' => 'Meu perfil', 'path' => '/me', 'icon' => 'profile', 'permission' => null, 'order' => 90, 'description' => 'Seus dados.'],
    ['label' => 'Perfis e permissões', 'path' => '/profiles', 'icon' => 'shield', 'permission' => null, 'order' => 100, 'description' => 'Matriz.'],
    ['label' => 'Painel', 'path' => '/dashboard', 'icon' => 'dashboard', 'permission' => Permissions::DASHBOARD_VIEW, 'order' => 20, 'description' => 'Ainda sem rota.'],
];
PHP;
    }

    private static function tempDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        mkdir($directory, 0777, true);
        return $directory;
    }

    private static function write(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $content);
    }
}
