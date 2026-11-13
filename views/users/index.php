<?php
declare(strict_types=1);

/**
 * Usuários internos (issues #36 e #37, somente ADMIN): busca por nome ou e-mail, filtros de perfil e situação,
 * lista paginada (20 por página) e formulário de cadastro com confirmação de senha.
 *
 * @var list<array<string, mixed>>  $users
 * @var array<string, int>          $pagination     page, pages, total, per_page
 * @var array{q: string, profile: string, status: string} $filters
 * @var array<string, string>       $profileOptions código => rótulo dos perfis com conta
 * @var array<string, string>|null  $errors
 * @var bool|null                   $openForm
 */
$usList = isset($users) && is_array($users) ? $users : [];
$usPager = isset($pagination) && is_array($pagination) ? $pagination : ['page' => 1, 'pages' => 1, 'total' => 0];
$usFilters = isset($filters) && is_array($filters) ? $filters : ['q' => '', 'profile' => '', 'status' => ''];
$usProfiles = isset($profileOptions) && is_array($profileOptions) ? $profileOptions : [];
$usHasErrors = isset($errors) && is_array($errors) && $errors !== [];
$usFiltered = $usFilters['q'] !== '' || $usFilters['profile'] !== '' || $usFilters['status'] !== '';
$usQuery = array_filter([
    'q' => $usFilters['q'],
    'profile' => $usFilters['profile'],
    'status' => $usFilters['status'],
], static fn (string $value): bool => $value !== '');
$usStatuses = ['' => 'Todas', 'active' => 'Ativos', 'inactive' => 'Inativos'];
?>
<section aria-labelledby="users-title" class="stack">
  <div class="section__header">
    <h2 id="users-title">Usuários internos</h2>
    <p class="section__subtitle">
      <?= e((string)$usPager['total']) ?> <?= $usPager['total'] === 1 ? 'usuário encontrado' : 'usuários encontrados' ?>.
      O perfil define o que cada pessoa pode fazer no sistema; responsáveis não têm conta.
    </p>
  </div>

  <form method="get" action="<?= e(url('/users')) ?>" class="toolbar" role="search" aria-label="Buscar usuários">
    <div class="field grow">
      <label class="field__label" for="filter-q">Nome ou e-mail</label>
      <input class="input" type="search" id="filter-q" name="q" value="<?= e($usFilters['q']) ?>" maxlength="100">
    </div>
    <div class="field">
      <label class="field__label" for="filter-profile">Perfil</label>
      <select class="select" id="filter-profile" name="profile">
        <option value="">Todos os perfis</option>
        <?php foreach ($usProfiles as $profileCode => $profileLabel): ?>
          <option value="<?= e((string)$profileCode) ?>"<?= (string)$profileCode === $usFilters['profile'] ? ' selected' : '' ?>><?= e($profileLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="filter-status">Situação</label>
      <select class="select" id="filter-status" name="status">
        <?php foreach ($usStatuses as $statusCode => $statusLabel): ?>
          <option value="<?= e($statusCode) ?>"<?= $statusCode === $usFilters['status'] ? ' selected' : '' ?>><?= e($statusLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn btn--secondary"><?= partial('icon', ['name' => 'search']) ?><span>Buscar</span></button>
    <?php if ($usFiltered): ?>
      <a class="btn btn--quiet" href="<?= e(url('/users')) ?>">Limpar</a>
    <?php endif; ?>
  </form>

  <?php if ($usList === []): ?>
    <?= partial('empty_state', [
        'icon' => 'users',
        'title' => $usFiltered ? 'Nenhum usuário corresponde à busca' : 'Nenhum usuário cadastrado',
        'text' => $usFiltered ? 'Confira o nome ou o e-mail digitados, ou limpe os filtros.' : 'Cadastre o primeiro usuário no formulário abaixo.',
    ]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Lista de usuários" tabindex="0">
      <table class="table">
        <caption class="sr-only">Usuários internos com e-mail, perfil, situação e último acesso</caption>
        <thead>
          <tr>
            <th scope="col">Nome</th>
            <th scope="col">E-mail</th>
            <th scope="col">Perfil</th>
            <th scope="col">Situação</th>
            <th scope="col">Último acesso</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($usList as $account): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/users/' . $account['id'])) ?>"><?= e($account['name']) ?></a></th>
              <td><?= e($account['email']) ?></td>
              <td><?= badge('profile', $account['profile']) ?></td>
              <td><?= partial('registry_status', ['active' => $account['active'], 'active_label' => 'Ativo', 'off_label' => 'Inativo']) ?></td>
              <td class="nowrap"><?= e($account['last_login_at'] !== null ? format_datetime($account['last_login_at']) : 'Nunca entrou') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= partial('pagination', ['page' => $usPager['page'], 'pages' => $usPager['pages'], 'base' => '/users', 'query' => $usQuery]) ?>
  <?php endif; ?>
</section>

<details class="card registry-disclosure"<?= !empty($openForm) ? ' open' : '' ?>>
  <summary class="registry-disclosure__summary">
    <?= partial('icon', ['name' => 'plus']) ?>
    <span>Cadastrar usuário</span>
  </summary>
  <form method="post" action="<?= e(url('/users')) ?>" class="form registry-disclosure__body">
    <?= csrf_field() ?>
    <?php if ($usHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
    <?= partial('field', ['name' => 'name', 'label' => 'Nome completo', 'required' => true, 'autocomplete' => 'off', 'attrs' => ['maxlength' => '120']]) ?>
    <div class="form__row">
      <?= partial('field', ['name' => 'email', 'label' => 'E-mail de acesso', 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'hint' => 'Usado para entrar no sistema. Um usuário por e-mail.', 'attrs' => ['maxlength' => '254']]) ?>
      <?= partial('field', ['name' => 'profile', 'label' => 'Perfil', 'type' => 'select', 'required' => true, 'options' => $usProfiles, 'placeholder' => 'Selecione o perfil']) ?>
    </div>
    <div class="form__row">
      <?= partial('field', ['name' => 'password', 'label' => 'Senha inicial', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'hint' => 'Mínimo de 10 caracteres. Entregue-a à pessoa por um canal seguro.', 'attrs' => ['minlength' => '10', 'maxlength' => '72']]) ?>
      <?= partial('field', ['name' => 'password_confirmation', 'label' => 'Repita a senha', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'toggle' => true, 'attrs' => ['minlength' => '10', 'maxlength' => '72']]) ?>
    </div>
    <div class="form__actions">
      <button type="submit" class="btn btn--primary">Cadastrar usuário</button>
    </div>
  </form>
</details>
