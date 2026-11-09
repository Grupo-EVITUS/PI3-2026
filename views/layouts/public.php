<?php
declare(strict_types=1);

/**
 * Layout público (responsável no link individual de justificativa): cabeçalho simples com a marca,
 * sem menu e sem dados do sistema interno. O layout imprime o único <h1> (o $title).
 *
 * @var string $title    título da página (<title> e <h1>)
 * @var string $content  HTML já renderizado da view (confiável)
 */
$pageTitle = isset($title) && is_string($title) && $title !== '' ? $title : 'SAA';
$pageContent = isset($content) && is_string($content) ? $content : '';
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
<body class="public-body">
  <a class="skip-link" href="#conteudo">Ir para o conteúdo principal</a>

  <header class="public-header">
    <div class="public-header__inner">
      <?= partial('brand', ['variant' => 'on-light']) ?>
    </div>
  </header>

  <main id="conteudo" class="public-main" tabindex="-1">
    <h1><?= e($pageTitle) ?></h1>
    <?= partial('flash') ?>
    <?= $pageContent ?>
  </main>

  <footer class="public-footer">
    SAA — Sistema de Ausência de Alunos · Grupo EVITUS · PI3 2026
  </footer>
</body>
</html>
