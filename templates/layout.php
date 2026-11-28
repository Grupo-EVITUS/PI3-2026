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
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/icon-192.png">
    <link rel="apple-touch-icon" href="/assets/icon-192.png">
    <title><?= e($pageTitle) ?> · Presença</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/chamada.css">
    <link rel="stylesheet" href="/assets/frequencia.css">
    <link rel="stylesheet" href="/assets/ocorrencias.css">
    <script src="/assets/push.js" defer></script>
    <script src="/assets/chamada.js" defer></script>
    <script src="/assets/frequencia.js" defer></script>
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= isset($_SESSION['user_id']) ? staffHomePath() : '/login' ?>">
        <span class="brand-mark">SAA</span>
        <strong>Comunicação escolar</strong>
    </a>
    <?php if (!empty($_SESSION['user_id'])): ?>
        <nav class="main-nav" aria-label="Navegação principal">
            <a href="/ocorrencias">Ocorrências</a>
            <?php if (in_array($_SESSION['profile'] ?? '', ['AOE', 'COORDENACAO'], true)): ?>
                <a href="/chamada">Chamada</a>
            <?php endif; ?>
            <?php if (($_SESSION['profile'] ?? '') === 'COORDENACAO'): ?>
                <a href="/cadastros">Cadastros</a>
                <a href="/funcionarios">Funcionários</a>
            <?php endif; ?>
            <?php if (in_array($_SESSION['profile'] ?? '', ['COORDENACAO', 'DIRETOR'], true)): ?>
                <a href="/frequencia">Frequência</a>
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
