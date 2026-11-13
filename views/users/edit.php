<?php
declare(strict_types=1);

/**
 * Ficha de um usuário interno (issues #36 e #37, somente ADMIN): dados de acesso, edição de nome/perfil/situação
 * e definição de uma nova senha pelo administrador. O hash da senha nunca chega a esta tela.
 *
 * @var array<string, mixed>       $account        usuário (UserService::present)
 * @var bool                       $isSelf         o administrador está vendo o próprio cadastro?
 * @var array<string, string>      $profileOptions código => rótulo
 * @var string|null                $activeForm     "account" | "password" (formulário que voltou com erro)
 * @var array<string, string>|null $errors
 */
$ueUser = isset($account) && is_array($account) ? $account : [];
$ueSelf = !empty($isSelf);
$ueProfiles = isset($profileOptions) && is_array($profileOptions) ? $profileOptions : [];
$ueForm = isset($activeForm) && is_string($activeForm) ? $activeForm : null;
$ueHasErrors = isset($errors) && is_array($errors) && $errors !== [];
$ueId = (string)($ueUser['id'] ?? '');
$ueActiveValue = (string)old('active', !empty($ueUser['active']) ? '1' : '0');
// Os erros só aparecem no formulário que foi enviado ('' = sem erro; null faria o campo usar field_error()).
$ueError = static fn (string $form, string $field): string => $ueForm === $form ? (string)field_error($field) : '';
?>
<p><a class="link-action" href="<?= e(url('/users')) ?>"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Voltar aos usuários</a></p>

<section class="card" aria-labelledby="user-data-title">
  <div class="card__header">
    <div>
      <h2 class="card__title" id="user-data-title">Dados de acesso</h2>
      <?php if ($ueSelf): ?>
        <p class="card__subtitle">Este é o seu usuário.</p>
      <?php endif; ?>
    </div>
    <?= partial('registry_status', ['active' => $ueUser['active'] ?? false, 'active_label' => 'Ativo', 'off_label' => 'Inativo']) ?>
  </div>
  <dl class="kv">
    <dt>E-mail</dt><dd><?= e($ueUser['email'] ?? '') ?></dd>
    <dt>Perfil</dt><dd><?= badge('profile', $ueUser['profile'] ?? '') ?></dd>
    <dt>Último acesso</dt><dd><?= e(($ueUser['last_login_at'] ?? null) !== null ? format_datetime($ueUser['last_login_at']) : 'Nunca entrou') ?></dd>
    <dt>Senha alterada em</dt><dd><?= e(format_datetime($ueUser['password_changed_at'] ?? null)) ?></dd>
    <dt>Cadastrado em</dt><dd><?= e(format_datetime($ueUser['created_at'] ?? null)) ?></dd>
  </dl>
</section>

<section class="card" aria-labelledby="user-edit-title">
  <h2 class="card__title" id="user-edit-title">Editar usuário</h2>
  <form method="post" action="<?= e(url('/users/' . $ueId)) ?>" class="form">
    <?= csrf_field() ?>
    <?php if ($ueHasErrors && $ueForm === 'account'): ?><?= partial('registry_form_alert') ?><?php endif; ?>
    <?= partial('field', ['name' => 'name', 'label' => 'Nome completo', 'required' => true, 'value' => old('name', $ueUser['name'] ?? ''), 'error' => $ueError('account', 'name'), 'attrs' => ['maxlength' => '120']]) ?>
    <div class="form__row">
      <?= partial('field', [
          'name' => 'profile', 'label' => 'Perfil', 'type' => 'select', 'required' => true,
          'options' => $ueProfiles, 'value' => old('profile', $ueUser['profile'] ?? ''), 'error' => $ueError('account', 'profile'),
          'hint' => $ueSelf ? 'Você não pode retirar o seu próprio perfil de Administrador.' : 'Vale a partir da próxima página que a pessoa abrir.',
      ]) ?>
      <?= partial('field', [
          'name' => 'active', 'label' => 'Situação', 'type' => 'select', 'required' => true,
          'options' => ['1' => 'Ativo (pode entrar no sistema)', '0' => 'Inativo (acesso bloqueado)'],
          'value' => $ueActiveValue, 'error' => $ueError('account', 'active'),
          'hint' => $ueSelf ? 'Você não pode desativar o seu próprio usuário.' : 'O sistema sempre mantém pelo menos um administrador ativo.',
      ]) ?>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary">Salvar alterações</button>
      <a class="btn btn--quiet" href="<?= e(url('/users')) ?>">Cancelar</a>
    </div>
  </form>
</section>

<section class="card" aria-labelledby="user-password-title">
  <h2 class="card__title" id="user-password-title">Definir nova senha</h2>
  <p class="card__subtitle">Use quando a pessoa esquecer a senha. A senha atual deixa de valer imediatamente.</p>
  <form method="post" action="<?= e(url('/users/' . $ueId . '/password')) ?>" class="form">
    <?= csrf_field() ?>
    <?php if ($ueHasErrors && $ueForm === 'password'): ?><?= partial('registry_form_alert') ?><?php endif; ?>
    <div class="form__row">
      <?= partial('field', ['name' => 'password', 'id' => 'new-password', 'label' => 'Nova senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'error' => $ueError('password', 'password'), 'hint' => 'Mínimo de 10 caracteres.', 'attrs' => ['minlength' => '10', 'maxlength' => '72']]) ?>
      <?= partial('field', ['name' => 'password_confirmation', 'id' => 'new-password-confirmation', 'label' => 'Repita a nova senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'error' => $ueError('password', 'password_confirmation'), 'attrs' => ['minlength' => '10', 'maxlength' => '72']]) ?>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--secondary">Definir nova senha</button>
    </div>
  </form>
</section>
