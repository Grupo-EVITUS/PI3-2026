<?php
$flashMessage = consumeFlash();
$pageTitle = $pageTitle ?? 'Controle de ausências';
$profileLabels = ['AOE' => 'AOE', 'COORDENACAO' => 'Coordenação', 'DIRETOR' => 'Diretor'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#183c48">
    <title><?= e($pageTitle) ?> · Presença</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= isset($_SESSION['user_id']) ? '/ocorrencias' : '/login' ?>">
        <span class="brand-mark" aria-hidden="true">P</span>
        <span><strong>Presença</strong><small>Comunicação escolar</small></span>
    </a>
    <?php if (!empty($_SESSION['user_id'])): ?>
        <nav class="main-nav" aria-label="Navegação principal">
            <a href="/ocorrencias">Ocorrências</a>
            <?php if (in_array($_SESSION['profile'] ?? '', ['AOE', 'COORDENACAO'], true)): ?>
                <a href="/ausencias/registrar">Registrar ausência</a>
            <?php endif; ?>
            <?php if (($_SESSION['profile'] ?? '') === 'COORDENACAO'): ?>
                <a href="/cadastros">Cadastros</a>
            <?php endif; ?>
        </nav>
        <div class="user-menu">
            <span class="user-label"><?= e($_SESSION['user_name'] ?? '') ?><small><?= e($profileLabels[$_SESSION['profile'] ?? ''] ?? '') ?></small></span>
            <form method="post" action="/logout">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <button class="button button-quiet" type="submit">Sair</button>
            </form>
        </div>
    <?php endif; ?>
</header>
<main class="page-shell">
    <?php if ($flashMessage): ?>
        <div class="notice notice-<?= e($flashMessage['kind']) ?>" role="status"><?= e($flashMessage['message']) ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
<footer class="site-footer">Sistema de Controle de Ausências e Justificativas · Dados para uso escolar autorizado</footer>
</body>
</html>
