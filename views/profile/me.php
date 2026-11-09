<?php
declare(strict_types=1);

/**
 * "Meu perfil", dentro de layouts/app: dados da conta e o que o perfil do usuário permite.
 * O <h1> vem do layout. Campos opcionais do usuário (último acesso, criação) só aparecem se existirem.
 *
 * @var array{id?:string,name?:string,email?:string,profile?:string,last_login_at?:mixed,created_at?:mixed} $user
 * @var list<array{code:string,label:string,granted:bool}>                                                 $permissions
 */
$meUser = isset($user) && is_array($user) ? $user : [];
$meName = (string)($meUser['name'] ?? '');
$meEmail = (string)($meUser['email'] ?? '');
$meProfile = (string)($meUser['profile'] ?? '');
$mePermissions = isset($permissions) && is_array($permissions) ? array_values($permissions) : [];
$meGranted = count(array_filter($mePermissions, static fn ($permission): bool => !empty($permission['granted'])));
$meLastLogin = $meUser['last_login_at'] ?? null;
$meCreated = $meUser['created_at'] ?? null;
?>
<div class="grid grid--2">
  <section class="card" aria-labelledby="account-title">
    <div class="card__header">
      <h2 class="card__title" id="account-title">Dados da conta</h2>
    </div>

    <div class="account">
      <?= partial('avatar', ['name' => $meName, 'size' => 'lg']) ?>
      <div>
        <p class="account__name"><?= e($meName !== '' ? $meName : 'Usuário interno') ?></p>
        <p class="account__mail"><?= e($meEmail) ?></p>
      </div>
    </div>

    <dl class="kv">
      <dt>Perfil</dt>
      <dd><?= $meProfile !== '' ? badge('profile', $meProfile) : '—' ?></dd>
      <dt>E-mail</dt>
      <dd><?= e($meEmail !== '' ? $meEmail : '—') ?></dd>
      <?php if ($meLastLogin !== null && $meLastLogin !== ''): ?>
        <dt>Último acesso</dt>
        <dd><?= e(format_datetime($meLastLogin)) ?></dd>
      <?php endif; ?>
      <?php if ($meCreated !== null && $meCreated !== ''): ?>
        <dt>Conta criada em</dt>
        <dd><?= e(format_date($meCreated)) ?></dd>
      <?php endif; ?>
    </dl>

    <div class="card__footer">
      <a class="btn btn--secondary" href="<?= e(url('/profiles')) ?>">
        <?= partial('icon', ['name' => 'shield']) ?>
        <span>Ver todos os perfis e permissões</span>
      </a>
    </div>
  </section>

  <section class="card" aria-labelledby="permissions-title">
    <div class="card__header">
      <div>
        <h2 class="card__title" id="permissions-title">O que o seu perfil permite</h2>
        <p class="card__subtitle"><?= e((string)$meGranted) ?> de <?= e((string)count($mePermissions)) ?> permissões liberadas.</p>
      </div>
    </div>

    <?php if ($mePermissions === []): ?>
      <?= partial('empty_state', [
          'icon' => 'shield',
          'title' => 'Nenhuma permissão encontrada',
          'text' => 'Não foi possível listar as permissões do seu perfil. Procure o administrador do sistema.',
      ]) ?>
    <?php else: ?>
      <ul class="perm-list">
        <?php foreach ($mePermissions as $permission): ?>
          <?php $permGranted = !empty($permission['granted']); ?>
          <li class="perm-list__item <?= $permGranted ? 'is-granted' : 'is-denied' ?>">
            <span class="perm-list__mark" aria-hidden="true"><?= partial('icon', ['name' => $permGranted ? 'check' : 'close', 'class' => 'icon--sm']) ?></span>
            <span>
              <span class="perm-list__label"><?= e($permission['label'] ?? '') ?></span>
              <span class="sr-only"> — <?= $permGranted ? 'permitido' : 'não permitido' ?></span>
              <span class="perm-code"><?= e($permission['code'] ?? '') ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
