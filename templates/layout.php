<?php
$flashMessage = consumeFlash();
$pageTitle = $pageTitle ?? 'Controle de ausências';
$profile = $_SESSION['profile'] ?? '';
$loggedIn = !empty($_SESSION['user_id']);
$profileLabels = ['AOE' => 'AOE', 'COORDENACAO' => 'Coordenação', 'DIRETOR' => 'Diretor'];
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
// Menu por perfil: [rótulo, endereço, ícone, prefixos que marcam a área como atual].
$menu = [];
if ($loggedIn) {
    $menu[] = ['Início', '/inicio', '🏠', ['/inicio']];
    if (in_array($profile, ['AOE', 'COORDENACAO'], true)) {
        $menu[] = ['Chamada', '/chamada', '📋', ['/chamada']];
    }
    $menu[] = ['Ocorrências', '/ocorrencias', '🗂️', ['/ocorrencias', '/ausencias/']];
    if (in_array($profile, ['COORDENACAO', 'DIRETOR'], true)) {
        $menu[] = ['Frequência', '/frequencia', '📊', ['/frequencia']];
    }
    if ($profile === 'COORDENACAO') {
        $menu[] = ['Cadastros', '/cadastros', '🏫', ['/cadastros']];
        $menu[] = ['Funcionários', '/funcionarios', '👤', ['/funcionarios']];
    }
}
$isCurrent = static function (array $prefixes) use ($currentPath): bool {
    foreach ($prefixes as $prefix) {
        if ($currentPath === $prefix || (str_ends_with($prefix, '/') && str_starts_with($currentPath, $prefix))) {
            return true;
        }
    }
    return false;
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#183c48">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <title><?= e($pageTitle) ?> · Presença</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/push.js" defer></script>
    <script src="/assets/app.js" defer></script>
</head>
<body>
<a class="skip-link" href="#conteudo">Ir para o conteúdo</a>
<header class="site-header">
    <a class="brand" href="<?= $loggedIn ? staffHomePath() : '/login' ?>">
        <span class="brand-mark" aria-hidden="true">P</span>
        <span><strong>Presença</strong><small>Comunicação escolar</small></span>
    </a>
    <?php if ($loggedIn): ?>
        <input class="menu-toggle" type="checkbox" id="menu-toggle" aria-label="Abrir o menu">
        <label class="menu-button" for="menu-toggle"><span aria-hidden="true">☰</span> Menu</label>
        <nav class="main-nav" aria-label="Navegação principal">
            <?php foreach ($menu as [$label, $href, $icon, $prefixes]): ?>
                <a href="<?= e($href) ?>"<?= $isCurrent($prefixes) ? ' aria-current="page"' : '' ?>><span class="nav-icon" aria-hidden="true"><?= $icon ?></span><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="user-menu">
            <span class="user-label"><?= e($_SESSION['user_name'] ?? '') ?><small><?= e($profileLabels[$profile] ?? '') ?></small></span>
            <form method="post" action="/logout">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button class="button button-quiet" type="submit" data-no-lock>Sair</button>
            </form>
        </div>
    <?php endif; ?>
</header>
<main class="page-shell" id="conteudo" tabindex="-1">
    <?php if ($loggedIn && !empty($crumbs)): ?>
        <nav class="breadcrumb" aria-label="Você está em">
            <a href="<?= e(staffHomePath()) ?>">Início</a>
            <?php foreach ($crumbs as [$crumbLabel, $crumbHref]): ?>
                <span aria-hidden="true">›</span>
                <?php if ($crumbHref !== null): ?>
                    <a href="<?= e($crumbHref) ?>"><?= e($crumbLabel) ?></a>
                <?php else: ?>
                    <span aria-current="page"><?= e($crumbLabel) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if ($flashMessage): ?>
        <div class="notice notice-<?= e($flashMessage['kind']) ?>" role="status" data-flash>
            <span><?= e($flashMessage['message']) ?></span>
            <button class="notice-close" type="button" data-dismiss aria-label="Fechar aviso">×</button>
        </div>
    <?php endif; ?>
    <?= $content ?>
</main>
<footer class="site-footer">Sistema de Controle de Ausências e Justificativas · Dados para uso escolar autorizado</footer>
</body>
</html>
