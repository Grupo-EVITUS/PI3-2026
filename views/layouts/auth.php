<?php
declare(strict_types=1);

/**
 * Layout do visitante (login, esqueci a senha, erros sem sessão): cartão central com a marca.
 * O layout imprime o único <h1> da página (o $title).
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
<body class="auth-body">
  <a class="skip-link" href="#conteudo">Ir para o conteúdo principal</a>

  <main id="conteudo" class="auth-shell" tabindex="-1">
    <div class="auth-card">
      <?= partial('brand', ['variant' => 'on-light']) ?>
      <h1 class="auth-card__title"><?= e($pageTitle) ?></h1>
      <?= partial('flash') ?>
      <?= $pageContent ?>
    </div>
    <p class="auth-footer">Sistema de Ausência de Alunos · Grupo EVITUS · PI3 2026</p>
  </main>
</body>
</html>
