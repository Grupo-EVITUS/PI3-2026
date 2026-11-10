<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Alunos (issue #38): busca por nome/matrícula, filtros por turma e situação, lista paginada (20 por página) com a
 * turma atual e formulário de cadastro (que já matricula o aluno numa turma do período ativo).
 *
 * @var list<array<string, mixed>>  $students
 * @var array<string, int>          $pagination  page, pages, total, per_page
 * @var array{q: string, class: string, status: string} $filters
 * @var array<string, string>       $classOptions turmas do período ativo (id => rótulo)
 * @var array<string, mixed>|null   $activePeriod
 * @var array<string, string>|null  $errors
 * @var bool|null                   $openForm
 */
$studentList = isset($students) && is_array($students) ? $students : [];
$studentPager = isset($pagination) && is_array($pagination) ? $pagination : ['page' => 1, 'pages' => 1, 'total' => 0];
$studentFilters = isset($filters) && is_array($filters) ? $filters : ['q' => '', 'class' => '', 'status' => 'active'];
$studentClasses = isset($classOptions) && is_array($classOptions) ? $classOptions : [];
$studentPeriod = isset($activePeriod) && is_array($activePeriod) ? $activePeriod : null;
$studentCanEdit = can(Permissions::REGISTRY_EDIT);
$studentHasErrors = isset($errors) && is_array($errors) && $errors !== [];
$studentArchived = $studentFilters['status'] === 'archived';
$studentFiltered = $studentFilters['q'] !== '' || $studentFilters['class'] !== '';
$studentQuery = array_filter([
    'q' => $studentFilters['q'],
    'class' => $studentFilters['class'],
    'status' => $studentArchived ? 'archived' : '',
], static fn (string $value): bool => $value !== '');
?>
<section aria-labelledby="students-title" class="stack">
  <div class="section__header">
    <h2 id="students-title">Alunos</h2>
    <p class="section__subtitle">
      <?= e((string)$studentPager['total']) ?> <?= $studentPager['total'] === 1 ? 'aluno encontrado' : 'alunos encontrados' ?><?= $studentArchived ? ' entre os arquivados' : '' ?>.
    </p>
  </div>

  <form method="get" action="<?= e(url('/students')) ?>" class="toolbar" role="search" aria-label="Buscar alunos">
    <div class="field grow">
      <label class="field__label" for="filter-q">Nome ou matrícula escolar</label>
      <input class="input" type="search" id="filter-q" name="q" value="<?= e($studentFilters['q']) ?>" maxlength="100">
    </div>
    <div class="field">
      <label class="field__label" for="filter-class">Turma</label>
      <select class="select" id="filter-class" name="class">
        <option value="">Todas as turmas</option>
        <?php foreach ($studentClasses as $classId => $classLabel): ?>
          <option value="<?= e((string)$classId) ?>"<?= (string)$classId === $studentFilters['class'] ? ' selected' : '' ?>><?= e($classLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="filter-status">Situação</label>
      <select class="select" id="filter-status" name="status">
        <option value="active"<?= !$studentArchived ? ' selected' : '' ?>>Ativos</option>
        <option value="archived"<?= $studentArchived ? ' selected' : '' ?>>Arquivados</option>
      </select>
    </div>
    <button type="submit" class="btn btn--secondary"><?= partial('icon', ['name' => 'search']) ?><span>Buscar</span></button>
    <?php if ($studentFiltered || $studentArchived): ?>
      <a class="btn btn--quiet" href="<?= e(url('/students')) ?>">Limpar</a>
    <?php endif; ?>
  </form>

  <?php if ($studentList === []): ?>
    <?= partial('empty_state', [
        'icon' => 'students',
        'title' => $studentFiltered ? 'Nenhum aluno corresponde à busca' : ($studentArchived ? 'Nenhum aluno arquivado' : 'Nenhum aluno cadastrado'),
        'text' => $studentFiltered ? 'Confira o nome ou a matrícula digitados, ou limpe os filtros.' : ($studentCanEdit && !$studentArchived ? 'Cadastre o primeiro aluno no formulário abaixo.' : null),
    ]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-label="Lista de alunos" tabindex="0">
      <table class="table">
        <caption class="sr-only">Alunos com matrícula escolar, turma atual e número de responsáveis</caption>
        <thead>
          <tr>
            <th scope="col">Aluno</th>
            <th scope="col">Matrícula escolar</th>
            <th scope="col">Turma atual</th>
            <th scope="col">Nascimento</th>
            <th scope="col" class="num">Responsáveis</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($studentList as $student): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/students/' . $student['id'])) ?>"><?= e($student['name']) ?></a></th>
              <td><?= e($student['student_code']) ?></td>
              <td>
                <?php if ($student['class'] !== null): ?>
                  <a href="<?= e(url('/classes/' . $student['class']['id'])) ?>"><?= e($student['class']['code']) ?></a>
                  <span class="text-muted"><?= e($student['class']['name']) ?></span>
                <?php else: ?>
                  <span class="badge badge--warning">Sem turma</span>
                <?php endif; ?>
              </td>
              <td><?= e(format_date($student['birth_date'])) ?></td>
              <td class="num">
                <?php if ($student['guardian_count'] === 0): ?>
                  <span class="badge badge--warning">Nenhum</span>
                <?php else: ?>
                  <?= e((string)$student['guardian_count']) ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?= partial('pagination', ['page' => $studentPager['page'], 'pages' => $studentPager['pages'], 'base' => '/students', 'query' => $studentQuery]) ?>
  <?php endif; ?>
</section>

<?php if ($studentCanEdit): ?>
  <details class="card registry-disclosure"<?= !empty($openForm) ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'plus']) ?>
      <span>Cadastrar aluno</span>
    </summary>
    <div class="registry-disclosure__body stack">
      <?php if ($studentPeriod === null || $studentClasses === []): ?>
        <div class="alert alert--warning" role="status">
          <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
          <div class="alert__body">
            <?= $studentPeriod === null
                ? 'Não há período letivo ativo. Cadastre ou ative um período antes de cadastrar alunos.'
                : 'O período letivo ativo ainda não tem turmas. Cadastre uma turma antes de cadastrar alunos.' ?>
            <a href="<?= e(url($studentPeriod === null ? '/periods' : '/classes')) ?>"><?= $studentPeriod === null ? 'Ir para períodos letivos' : 'Ir para turmas' ?></a>
          </div>
        </div>
      <?php endif; ?>
      <form method="post" action="<?= e(url('/students')) ?>" class="form">
        <?= csrf_field() ?>
        <?php if ($studentHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
        <?= partial('field', ['name' => 'name', 'label' => 'Nome completo', 'required' => true, 'autocomplete' => 'off', 'attrs' => ['maxlength' => '120']]) ?>
        <div class="form__row">
          <?= partial('field', ['name' => 'student_code', 'label' => 'Matrícula escolar (RA)', 'required' => true, 'hint' => 'Código único do aluno na escola.', 'attrs' => ['maxlength' => '30']]) ?>
          <?= partial('field', ['name' => 'birth_date', 'label' => 'Data de nascimento', 'type' => 'date', 'required' => true]) ?>
        </div>
        <?= partial('field', [
            'name' => 'class_id', 'label' => 'Turma', 'type' => 'select', 'required' => true,
            'options' => $studentClasses, 'placeholder' => 'Selecione a turma',
            'hint' => $studentPeriod !== null ? 'Turmas do período letivo ativo (' . $studentPeriod['name'] . '). O aluno será matriculado nela.' : null,
        ]) ?>
        <div class="form__actions">
          <button type="submit" class="btn btn--primary">Cadastrar aluno</button>
        </div>
      </form>
    </div>
  </details>
<?php endif; ?>
