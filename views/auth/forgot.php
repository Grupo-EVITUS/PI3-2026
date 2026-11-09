<?php
declare(strict_types=1);

/**
 * Página informativa de /forgot-password (layouts/auth). A redefinição por e-mail só chega na Etapa 3;
 * por enquanto, orienta procurar o administrador. Sem variáveis obrigatórias.
 */
?>
<p class="auth-card__lead">A redefinição de senha pelo próprio sistema ainda não está disponível.</p>

<div class="alert alert--info">
  <?= partial('icon', ['name' => 'info', 'class' => 'alert__icon']) ?>
  <div class="alert__body">
    <span class="alert__title">Procure o administrador do SAA</span>
    Peça à pessoa responsável pelo sistema na sua escola para definir uma nova senha para o seu acesso. Informe o seu nome e o e-mail cadastrado.
  </div>
</div>

<p class="auth-card__note">
  É responsável por um aluno? Você não precisa de senha: use o link que recebeu por e-mail para justificar a ausência.
</p>

<a class="btn btn--secondary btn--block" href="<?= e(url('/login')) ?>">
  <?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?>
  <span>Voltar para o login</span>
</a>
