<?php
declare(strict_types=1);

/**
 * Página de erro (funciona dentro de layouts/app e layouts/auth). O <h1> é impresso pelo layout
 * (com o $title); aqui ficam o código, a mensagem e a saída para o usuário.
 *
 * @var int    $status   código HTTP (404, 403, 401, 429, 500...)
 * @var string $title    título do erro (usado pelo layout)
 * @var string $message  explicação em linguagem simples
 */
$errorStatus = isset($status) && is_int($status) ? $status : 500;
$errorMessage = isset($message) && is_string($message) && $message !== '' ? $message : 'Não foi possível concluir a solicitação. Tente novamente em instantes.';
$errorIcon = match (true) {
    $errorStatus === 404 => 'search',
    $errorStatus === 401, $errorStatus === 403 => 'shield',
    default => 'alert',
};
$errorLoggedIn = is_array(current_user());
?>
<div class="state">
  <span class="state__icon" aria-hidden="true"><?= partial('icon', ['name' => $errorIcon, 'class' => 'icon--lg']) ?></span>
  <p class="state__code"><span class="sr-only">Erro </span><?= e((string)$errorStatus) ?></p>
  <p class="state__message"><?= e($errorMessage) ?></p>
  <div class="state__actions">
    <?php if ($errorLoggedIn): ?>
      <a class="btn btn--primary" href="<?= e(url('/home')) ?>"><?= partial('icon', ['name' => 'home']) ?> <span>Ir para o início</span></a>
    <?php else: ?>
      <a class="btn btn--primary" href="<?= e(url('/login')) ?>"><?= partial('icon', ['name' => 'arrow-right']) ?> <span>Ir para o login</span></a>
    <?php endif; ?>
  </div>
</div>
