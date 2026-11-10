<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Turmas (issue #40): filtros por período, turno e situação, lista com total de alunos e formulário de cadastro.
 *
 * @var list<array<string, mixed>>  $classes
 * @var array{period: string, shift: string, status: string} $filters
 * @var array<string, string>       $periodOptions  id => nome
 * @var string|null                 $activePeriodId
 * @var array<string, string>       $shiftOptions   código => rótulo
 * @var array<string, string>|null  $errors
 * @var bool|null                   $openForm
 */
$classList = isset($classes) && is_array($classes) ? $classes : [];
$classFilters = isset($filters) && is_array($filters) ? $filters : ['period' => 'all', 'shift' => '', 'status' => 'active'];
$classPeriods = isset($periodOptions) && is_array($periodOptions) ? $periodOptions : [];
$classShifts = isset($shiftOptions) && is_array($shiftOptions) ? $shiftOptions : [];
$classCanEdit = can(Permissions::REGISTRY_EDIT);
$classHasErrors = isset($errors) && is_array($errors) && $errors !== [];
$classFormOpen = !empty($openForm);
$classArchived = $classFilters['status'] === 'archived';
?>
<section aria-labelledby="classes-title" class="stack">
  <div class="section__header">
    <h2 id="classes-title">Turmas</h2>
    <p class="section__subtitle">Turmas por período letivo, com o número de alunos matriculados em cada uma.</p>
  </div>

  <form method="get" action="<?= e(url('/classes')) ?>" class="toolbar" role="search" aria-label="Filtrar turmas">
    <div class="field">
      <label class="field__label" for="filter-period">Período letivo</label>
      <select class="select" id="filter-period" name="period">
        <option value="all"<?= $classFilters['period'] === 'all' ? ' selected' : '' ?>>Todos os períodos</option>
        <?php foreach ($classPeriods as $periodId => $periodLabel): ?>
          <option value="<?= e((string)$periodId) ?>"<?= (string)$periodId === $classFilters['period'] ? ' selected' : '' ?>><?= e($periodLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="filter-shift">Turno</label>
      <select class="select" id="filter-shift" name="shift">
        <option value="">Todos os turnos</option>
        <?php foreach ($classShifts as $shiftCode => $shiftLabel): ?>
          <option value="<?= e((string)$shiftCode) ?>"<?= (string)$shiftCode === $classFilters['shift'] ? ' selected' : '' ?>><?= e($shiftLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="filter-status">Situação</label>
      <select class="select" id="filter-status" name="status">
        <option value="active"<?= !$classArchived ? ' selected' : '' ?>>Ativas</option>
        <option value="archived"<?= $classArchived ? ' selected' : '' ?>>Arquivadas</option>
      </select>
    </div>
    <button type="submit" class="btn btn--secondary"><?= partial('icon', ['name' => 'search']) ?><span>Filtrar</span></button>
  </form>

  <?php if ($classList === []): ?>
    <?= partial('empty_state', [
        'icon' => 'classes',
        'title' => $classArchived ? 'Nenhuma turma arquivada' : 'Nenhuma turma encontrada',
        'text' => $classPeriods === []
            ? 'Cadastre primeiro um período letivo; depois crie as turmas dele.'
            : ($classCanEdit && !$classArchived ? 'Ajuste os filtros ou cadastre uma turma no formulário abaixo.' : 'Ajuste os filtros para ver outras turmas.'),
        'action_href' => $classPeriods === [] ? '/periods' : null,
        'action_label' => $classPeriods === [] ? 'Ir para períodos letivos' : null,
    ]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Lista de turmas" tabindex="0">
      <table class="table">
        <caption class="sr-only">Turmas com série, turno, período e número de alunos</caption>
        <thead>
          <tr>
            <th scope="col">Código</th>
            <th scope="col">Nome</th>
            <th scope="col">Série</th>
            <th scope="col">Turno</th>
            <th scope="col">Período</th>
            <th scope="col" class="num">Alunos</th>
            <th scope="col">Situação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($classList as $class): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/classes/' . $class['id'])) ?>"><?= e($class['code']) ?></a></th>
              <td><?= e($class['name']) ?></td>
              <td><?= e($class['grade']) ?></td>
              <td><?= e(label('shift', $class['shift'])) ?></td>
              <td><?= e($class['period_name'] ?? '—') ?></td>
              <td class="num"><?= e((string)$class['student_count']) ?></td>
              <td><?= partial('registry_status', ['active' => $class['active'], 'active_label' => 'Ativa', 'off_label' => 'Arquivada']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($classCanEdit && $classPeriods !== []): ?>
  <details class="card registry-disclosure"<?= $classFormOpen ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'plus']) ?>
      <span>Cadastrar turma</span>
    </summary>
    <form method="post" action="<?= e(url('/classes')) ?>" class="form registry-disclosure__body">
      <?= csrf_field() ?>
      <?php if ($classHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <?= partial('field', [
          'name' => 'academic_period_id', 'label' => 'Período letivo', 'type' => 'select', 'required' => true,
          'options' => $classPeriods, 'placeholder' => 'Selecione o período',
          'value' => old('academic_period_id', $activePeriodId ?? ''),
      ]) ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'code', 'label' => 'Código', 'required' => true, 'hint' => 'Curto e único no período, por exemplo 6A.', 'attrs' => ['maxlength' => '20']]) ?>
        <?= partial('field', ['name' => 'name', 'label' => 'Nome exibido', 'required' => true, 'hint' => 'Por exemplo: 6º Ano A.', 'attrs' => ['maxlength' => '100']]) ?>
      </div>
      <div class="form__row">
        <?= partial('field', ['name' => 'grade', 'label' => 'Série ou ano', 'required' => true, 'hint' => 'Por exemplo: 6º ano.', 'attrs' => ['maxlength' => '50']]) ?>
        <?= partial('field', ['name' => 'shift', 'label' => 'Turno', 'type' => 'select', 'required' => true, 'options' => $classShifts, 'placeholder' => 'Selecione o turno']) ?>
      </div>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Cadastrar turma</button>
      </div>
    </form>
  </details>
<?php endif; ?>
