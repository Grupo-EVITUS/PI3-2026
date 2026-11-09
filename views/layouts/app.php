<?php
declare(strict_types=1);

/**
 * Layout da área autenticada: menu lateral, barra superior, mensagens flash e conteúdo.
 * O layout imprime o único <h1> da página (o $title); as views usam <h2> em diante.
 *
 * @var string      $title        título da página (<title> e <h1>)
 * @var string      $content      HTML já renderizado da view (confiável)
 * @var string|null $eyebrow      texto pequeno acima do título
 * @var string|null $pageActions  HTML opcional (confiável) para a barra superior (ex.: botões)
 * @var string|null $activeNav    caminho do item de menu ativo (opcional; nav_items() já marca o atual)
 */
$pageTitle = isset($title) && is_string($title) && $title !== '' ? $title : 'SAA';
$pageEyebrow = isset($eyebrow) && is_string($eyebrow) && $eyebrow !== '' ? $eyebrow : null;
$pageActionsHtml = isset($pageActions) && is_string($pageActions) && trim($pageActions) !== '' ? $pageActions : null;
$pageContent = isset($content) && is_string($content) ? $content : '';
$pageActiveNav = isset($activeNav) && is_string($activeNav) && $activeNav !== '' ? $activeNav : null;

$sessionUser = current_user();
$sessionName = is_array($sessionUser) ? (string)($sessionUser['name'] ?? '') : '';
$sessionProfile = is_array($sessionUser) ? (string)($sessionUser['profile'] ?? '') : '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#24376b">
  <title><?= e($pageTitle === 'SAA' ? 'SAA — Sistema de Ausência de Alunos' : $pageTitle . ' · SAA') ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= e(asset('img/favicon.svg')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/saa.css')) ?>">
  <script src="<?= e(asset('js/app.js')) ?>"></script>
</head>
<body class="app-body">
  <a class="skip-link" href="#conteudo">Ir para o conteúdo principal</a>

  <div class="app-shell">
    <aside class="sidebar" id="sidebar" aria-label="Barra lateral">
      <div class="sidebar__head">
        <?= partial('brand', ['href' => '/home', 'variant' => 'on-dark']) ?>
        <button type="button" class="sidebar__close" data-nav-close>
          <?= partial('icon', ['name' => 'close']) ?>
          <span class="sr-only">Fechar menu</span>
        </button>
      </div>

      <a class="user-chip" href="<?= e(url('/me')) ?>">
        <?= partial('avatar', ['name' => $sessionName]) ?>
        <span class="user-chip__text">
          <span class="user-chip__name"><?= e($sessionName !== '' ? $sessionName : 'Usuário interno') ?></span>
          <span class="user-chip__role"><?= e($sessionProfile !== '' ? profile_label($sessionProfile) : 'Acesso interno') ?><span class="sr-only"> — abrir meu perfil</span></span>
        </span>
      </a>

      <?= partial('nav', ['activeNav' => $pageActiveNav]) ?>

      <div class="sidebar__foot">
        <form method="post" action="<?= e(url('/logout')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn--on-dark btn--block">
            <?= partial('icon', ['name' => 'logout']) ?>
            <span>Sair</span>
          </button>
        </form>
        <p class="sidebar__note">Ambiente interno da escola</p>
      </div>
    </aside>
    <div class="backdrop" data-nav-backdrop></div>

    <div class="app-main" id="app-main">
      <header class="topbar">
        <button type="button" class="topbar__menu" aria-controls="sidebar" aria-expanded="false" data-nav-toggle>
          <?= partial('icon', ['name' => 'menu', 'class' => 'icon--lg']) ?>
          <span class="sr-only">Abrir menu</span>
        </button>
        <div class="topbar__title">
          <?php if ($pageEyebrow !== null): ?>
            <span class="eyebrow"><?= e($pageEyebrow) ?></span>
          <?php endif; ?>
          <h1><?= e($pageTitle) ?></h1>
        </div>
        <?php if ($pageActionsHtml !== null): ?>
          <div class="topbar__actions"><?= $pageActionsHtml ?></div>
        <?php endif; ?>
      </header>

      <main id="conteudo" class="page" tabindex="-1">
        <?= partial('flash') ?>
        <?= $pageContent ?>
      </main>

      <footer class="app-footer">
        SAA — Sistema de Ausência de Alunos · Grupo EVITUS · PI3 2026
      </footer>
    </div>
  </div>
</body>
</html>
