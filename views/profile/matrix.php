<?php
declare(strict_types=1);

/**
 * "Perfis e permissões", dentro de layouts/app: descrição de cada perfil e a matriz de permissões.
 * Fonte dos dados: Saa\Security\Permissions (o controller monta as listas). O <h1> vem do layout.
 *
 * @var list<array{code:string,label:string,description:string,login:bool}> $profiles
 * @var array<string,string>                                                 $permissions  código => descrição
 * @var array<string,list<string>>                                           $grants       perfil => códigos concedidos
 */
$matrixProfiles = isset($profiles) && is_array($profiles) ? array_values($profiles) : [];
$matrixPermissions = isset($permissions) && is_array($permissions) ? $permissions : [];
$matrixGrants = isset($grants) && is_array($grants) ? $grants : [];
$matrixUser = current_user();
$matrixCurrent = is_array($matrixUser) ? (string)($matrixUser['profile'] ?? '') : '';
?>
<section aria-labelledby="profiles-title">
  <div class="section__header">
    <h2 id="profiles-title">Perfis</h2>
    <p class="section__subtitle">Cada pessoa usa o SAA com um único perfil, que define o que ela pode ver e fazer.</p>
  </div>

  <?php if ($matrixProfiles === []): ?>
    <?= partial('empty_state', ['icon' => 'users', 'title' => 'Nenhum perfil cadastrado']) ?>
  <?php else: ?>
    <div class="grid grid--3">
      <?php foreach ($matrixProfiles as $profile): ?>
        <?php $profileCode = (string)($profile['code'] ?? ''); ?>
        <article class="card card--tight profile-card">
          <div class="profile-card__head">
            <h3 class="profile-card__name"><?= e($profile['label'] ?? $profileCode) ?></h3>
            <?php if ($profileCode !== '' && $profileCode === $matrixCurrent): ?>
              <span class="badge badge--info">Seu perfil</span>
            <?php elseif (empty($profile['login'])): ?>
              <span class="badge badge--neutral">Sem login</span>
            <?php endif; ?>
          </div>
          <p class="text-muted text-sm"><?= e($profile['description'] ?? '') ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section>
  <div class="section__header">
    <h2 id="matrix-title">O que cada perfil pode fazer</h2>
    <p class="section__subtitle">Uma marca indica que o perfil da coluna tem a permissão da linha. A coluna destacada é a do seu perfil.</p>
  </div>

  <?php if ($matrixPermissions === [] || $matrixProfiles === []): ?>
    <?= partial('empty_state', ['icon' => 'shield', 'title' => 'Matriz indisponível', 'text' => 'Não há perfis ou permissões para exibir.']) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="matrix-title" tabindex="0">
      <table class="table matrix">
        <caption class="sr-only">Permissões concedidas a cada perfil</caption>
        <thead>
          <tr>
            <th scope="col">Permissão</th>
            <?php foreach ($matrixProfiles as $profile): ?>
              <?php $profileCode = (string)($profile['code'] ?? ''); ?>
              <th scope="col"<?= $profileCode === $matrixCurrent ? ' class="is-current"' : '' ?>>
                <?= e($profile['label'] ?? $profileCode) ?>
                <?php if ($profileCode === $matrixCurrent): ?><span class="sr-only"> (seu perfil)</span><?php endif; ?>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($matrixPermissions as $permissionCode => $permissionLabel): ?>
            <tr>
              <th scope="row">
                <?= e($permissionLabel) ?>
                <span class="perm-code"><?= e((string)$permissionCode) ?></span>
              </th>
              <?php foreach ($matrixProfiles as $profile): ?>
                <?php
                $profileCode = (string)($profile['code'] ?? '');
                $cellGranted = in_array((string)$permissionCode, (array)($matrixGrants[$profileCode] ?? []), true);
                ?>
                <td<?= $profileCode === $matrixCurrent ? ' class="is-current"' : '' ?>>
                  <?php if ($cellGranted): ?>
                    <span class="matrix__mark matrix__mark--yes"><?= partial('icon', ['name' => 'check']) ?><span class="sr-only">Sim</span></span>
                  <?php else: ?>
                    <span class="matrix__mark matrix__mark--no"><span aria-hidden="true">—</span><span class="sr-only">Não</span></span>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
