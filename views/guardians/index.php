<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Responsáveis (issue #39): busca por nome/e-mail, filtro de situação, lista paginada e formulário de cadastro
 * (com vínculo opcional a um aluno pela matrícula escolar).
 *
 * @var list<array<string, mixed>>  $guardians
 * @var array<string, int>          $pagination
 * @var array{q: string, status: string} $filters
 * @var array<string, string>       $relationshipOptions
 * @var array<string, string>|null  $errors
 * @var bool|null                   $openForm
 */
$grList = isset($guardians) && is_array($guardians) ? $guardians : [];
$grPager = isset($pagination) && is_array($pagination) ? $pagination : ['page' => 1, 'pages' => 1, 'total' => 0];
$grFilters = isset($filters) && is_array($filters) ? $filters : ['q' => '', 'status' => 'active'];
$grRelationships = isset($relationshipOptions) && is_array($relationshipOptions) ? $relationshipOptions : [];
$grCanEdit = can(Permissions::REGISTRY_EDIT);
$grHasErrors = isset($errors) && is_array($errors) && $errors !== [];
$grArchived = $grFilters['status'] === 'archived';
$grQuery = array_filter(['q' => $grFilters['q'], 'status' => $grArchived ? 'archived' : ''], static fn (string $value): bool => $value !== '');
$grOld = isset($old) && is_array($old) ? $old : [];
$grNotify = !array_key_exists('receives_absence_notifications', $grOld) || in_array($grOld['receives_absence_notifications'], ['1', 'on'], true);
$grPrimary = in_array($grOld['is_primary'] ?? null, ['1', 'on'], true);
?>
<section aria-labelledby="guardians-title" class="stack">
  <div class="section__header">
    <h2 id="guardians-title">Responsáveis</h2>
    <p class="section__subtitle">
      <?= e((string)$grPager['total']) ?> <?= $grPager['total'] === 1 ? 'responsável encontrado' : 'responsáveis encontrados' ?><?= $grArchived ? ' entre os arquivados' : '' ?>.
      Só e-mails com situação "Válido" recebem os avisos de ausência.
    </p>
  </div>

  <form method="get" action="<?= e(url('/guardians')) ?>" class="toolbar" role="search" aria-label="Buscar responsáveis">
    <div class="field grow">
      <label class="field__label" for="filter-q">Nome ou e-mail</label>
      <input class="input" type="search" id="filter-q" name="q" value="<?= e($grFilters['q']) ?>" maxlength="100">
    </div>
    <div class="field">
      <label class="field__label" for="filter-status">Situação</label>
      <select class="select" id="filter-status" name="status">
        <option value="active"<?= !$grArchived ? ' selected' : '' ?>>Ativos</option>
        <option value="archived"<?= $grArchived ? ' selected' : '' ?>>Arquivados</option>
      </select>
    </div>
    <button type="submit" class="btn btn--secondary"><?= partial('icon', ['name' => 'search']) ?><span>Buscar</span></button>
    <?php if ($grFilters['q'] !== '' || $grArchived): ?>
      <a class="btn btn--quiet" href="<?= e(url('/guardians')) ?>">Limpar</a>
    <?php endif; ?>
  </form>

  <?php if ($grList === []): ?>
    <?= partial('empty_state', [
        'icon' => 'guardians',
        'title' => $grFilters['q'] !== '' ? 'Nenhum responsável corresponde à busca' : ($grArchived ? 'Nenhum responsável arquivado' : 'Nenhum responsável cadastrado'),
        'text' => $grFilters['q'] !== '' ? 'Confira o nome ou o e-mail digitados.' : ($grCanEdit && !$grArchived ? 'Cadastre um responsável abaixo ou pela ficha do aluno.' : null),
    ]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Lista de responsáveis" tabindex="0">
      <table class="table">
        <caption class="sr-only">Responsáveis com e-mail, situação do e-mail, telefone e número de alunos vinculados</caption>
        <thead>
          <tr>
            <th scope="col">Responsável</th>
            <th scope="col">E-mail</th>
            <th scope="col">Situação do e-mail</th>
            <th scope="col">Telefone</th>
            <th scope="col" class="num">Alunos</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($grList as $guardian): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/guardians/' . $guardian['id'])) ?>"><?= e($guardian['name']) ?></a></th>
              <td><?= e($guardian['email']) ?></td>
              <td><?= badge('email_status', $guardian['email_status']) ?></td>
              <td class="nowrap"><?= e($guardian['phone'] !== '' ? $guardian['phone'] : '—') ?></td>
              <td class="num"><?= e((string)$guardian['student_count']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= partial('pagination', ['page' => $grPager['page'], 'pages' => $grPager['pages'], 'base' => '/guardians', 'query' => $grQuery]) ?>
  <?php endif; ?>
</section>

<?php if ($grCanEdit): ?>
  <details class="card registry-disclosure"<?= !empty($openForm) ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'plus']) ?>
      <span>Cadastrar responsável</span>
    </summary>
    <form method="post" action="<?= e(url('/guardians')) ?>" class="form registry-disclosure__body">
      <?= csrf_field() ?>
      <?php if ($grHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <?= partial('field', ['name' => 'name', 'label' => 'Nome completo', 'required' => true, 'attrs' => ['maxlength' => '120']]) ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'hint' => 'Um cadastro por e-mail.', 'attrs' => ['maxlength' => '254']]) ?>
        <?= partial('field', ['name' => 'phone', 'label' => 'Telefone com DDD', 'type' => 'tel', 'required' => true, 'hint' => 'Por exemplo: (11) 99999-0001.', 'attrs' => ['maxlength' => '30', 'inputmode' => 'tel']]) ?>
      </div>
      <fieldset class="registry-fieldset">
        <legend class="field__label">Vincular a um aluno agora <span class="field__optional">(opcional)</span></legend>
        <div class="form__row">
          <?= partial('field', ['name' => 'student_code', 'label' => 'Matrícula escolar do aluno', 'hint' => 'Deixe em branco para vincular depois, pela ficha do aluno.', 'attrs' => ['maxlength' => '30']]) ?>
          <?= partial('field', ['name' => 'relationship', 'label' => 'Parentesco', 'type' => 'select', 'options' => $grRelationships, 'placeholder' => 'Selecione o parentesco', 'hint' => 'Obrigatório quando houver aluno.']) ?>
        </div>
        <label class="check">
          <input type="checkbox" name="is_primary" value="1"<?= $grPrimary ? ' checked' : '' ?>>
          <span>Responsável principal do aluno</span>
        </label>
        <input type="hidden" name="receives_absence_notifications" value="0">
        <label class="check">
          <input type="checkbox" name="receives_absence_notifications" value="1"<?= $grNotify ? ' checked' : '' ?>>
          <span>Recebe os avisos de ausência por e-mail</span>
        </label>
      </fieldset>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Cadastrar responsável</button>
      </div>
    </form>
  </details>
<?php endif; ?>
