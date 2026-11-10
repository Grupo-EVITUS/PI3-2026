<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Ficha da turma (issues #40 e #41): dados, alunos com matrícula ativa, associação de aluno (busca + seleção),
 * edição e arquivamento.
 *
 * @var array<string, mixed>            $class
 * @var list<array<string, mixed>>      $students
 * @var list<array<string, mixed>>|null $candidates   alunos encontrados para associar (null = sem busca)
 * @var string                          $studentTerm
 * @var array<string, string>           $shiftOptions
 * @var string|null                     $activeForm   formulário que voltou com erro: edit | enroll
 * @var string|null                     $formError    mensagem geral (ex.: arquivamento recusado)
 */
$classData = isset($class) && is_array($class) ? $class : [];
$classStudents = isset($students) && is_array($students) ? $students : [];
$classCandidates = isset($candidates) && is_array($candidates) ? $candidates : null;
$classTerm = isset($studentTerm) && is_string($studentTerm) ? $studentTerm : '';
$classShifts = isset($shiftOptions) && is_array($shiftOptions) ? $shiftOptions : [];
$classForm = isset($activeForm) && is_string($activeForm) ? $activeForm : null;
$classCanEdit = can(Permissions::REGISTRY_EDIT);
$classActive = !empty($classData['active']);
$classId = (string)($classData['id'] ?? '');
$classErrorFor = static fn (string $form, string $field): string => $classForm === $form ? (string)(field_error($field) ?? '') : '';
?>
<p><a class="link-action" href="<?= e(url('/classes')) ?>"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Voltar às turmas</a></p>

<?php if (isset($formError) && is_string($formError) && $formError !== ''): ?>
  <?= partial('registry_form_alert', ['message' => $formError]) ?>
<?php endif; ?>

<section class="card" aria-labelledby="class-data-title">
  <div class="card__header">
    <h2 class="card__title" id="class-data-title"><?= e(($classData['code'] ?? '') . ' — ' . ($classData['name'] ?? '')) ?></h2>
    <?= partial('registry_status', ['active' => $classActive, 'active_label' => 'Ativa', 'off_label' => 'Arquivada']) ?>
  </div>
  <dl class="kv">
    <dt>Série ou ano</dt><dd><?= e($classData['grade'] ?? '—') ?></dd>
    <dt>Turno</dt><dd><?= e(label('shift', $classData['shift'] ?? null)) ?></dd>
    <dt>Período letivo</dt>
    <dd>
      <?= e($classData['period_name'] ?? '—') ?>
      <?php if (!empty($classData['period_active'])): ?><span class="badge badge--info">Ativo</span><?php endif; ?>
    </dd>
    <dt>Alunos matriculados</dt><dd><?= e((string)count($classStudents)) ?></dd>
  </dl>
</section>

<section aria-labelledby="class-students-title" class="stack">
  <div class="section__header">
    <h2 id="class-students-title">Alunos matriculados</h2>
    <p class="section__subtitle">Somente matrículas ativas. Quem mudou de turma continua no histórico da própria ficha.</p>
  </div>
  <?php if ($classStudents === []): ?>
    <?= partial('empty_state', ['icon' => 'students', 'title' => 'Nenhum aluno matriculado nesta turma', 'text' => $classCanEdit && $classActive ? 'Use "Associar aluno" abaixo para matricular alunos.' : null]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="class-students-title" tabindex="0">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Aluno</th>
            <th scope="col">Matrícula escolar</th>
            <th scope="col">Nascimento</th>
            <th scope="col">Na turma desde</th>
            <th scope="col" class="num">Responsáveis</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($classStudents as $student): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/students/' . $student['id'])) ?>"><?= e($student['name']) ?></a></th>
              <td><?= e($student['student_code']) ?></td>
              <td><?= e(format_date($student['birth_date'])) ?></td>
              <td><?= e(format_date($student['since'])) ?></td>
              <td class="num"><?= e((string)$student['guardian_count']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($classCanEdit && $classActive): ?>
  <section class="card" aria-labelledby="class-enroll-title">
    <div class="card__header">
      <div>
        <h2 class="card__title" id="class-enroll-title">Associar aluno</h2>
        <p class="card__subtitle">Busque pelo nome ou pela matrícula escolar. Se o aluno estiver em outra turma, a matrícula anterior é encerrada (mudança de turma) e fica no histórico.</p>
      </div>
    </div>
    <form method="get" action="<?= e(url('/classes/' . $classId)) ?>" class="cluster registry-search" role="search" aria-label="Buscar aluno para associar">
      <div class="field grow">
        <label class="field__label" for="student_q">Nome ou matrícula do aluno</label>
        <input class="input" type="search" id="student_q" name="student_q" value="<?= e($classTerm) ?>" maxlength="100">
      </div>
      <button type="submit" class="btn btn--secondary"><?= partial('icon', ['name' => 'search']) ?><span>Buscar</span></button>
    </form>

    <?php $enrollError = $classErrorFor('enroll', 'student_id'); ?>
    <?php if ($enrollError !== ''): ?>
      <?= partial('registry_form_alert', ['message' => $enrollError]) ?>
    <?php endif; ?>

    <?php if ($classCandidates !== null): ?>
      <?php if ($classCandidates === []): ?>
        <p class="text-muted">Nenhum aluno ativo encontrado para "<?= e($classTerm) ?>" fora desta turma.</p>
      <?php else: ?>
        <form method="post" action="<?= e(url('/classes/' . $classId . '/students')) ?>" class="form">
          <?= csrf_field() ?>
          <fieldset class="registry-choices">
            <legend class="field__label">Selecione o aluno</legend>
            <?php foreach ($classCandidates as $index => $candidate): ?>
              <label class="check">
                <input type="radio" name="student_id" value="<?= e($candidate['id']) ?>"<?= $index === 0 ? ' required' : '' ?>>
                <span>
                  <strong><?= e($candidate['name']) ?></strong>
                  <span class="text-muted"> · <?= e($candidate['student_code']) ?> · <?= e($candidate['current_class'] !== null ? 'hoje na turma ' . $candidate['current_class'] : 'sem turma') ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </fieldset>
          <div class="form__actions">
            <button type="submit" class="btn btn--primary">Associar à turma <?= e($classData['code'] ?? '') ?></button>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($classCanEdit): ?>
  <details class="card registry-disclosure"<?= $classForm === 'edit' ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'classes']) ?>
      <span>Editar dados da turma</span>
    </summary>
    <form method="post" action="<?= e(url('/classes/' . $classId)) ?>" class="form registry-disclosure__body">
      <?= csrf_field() ?>
      <?php if ($classForm === 'edit'): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'code', 'label' => 'Código', 'required' => true, 'value' => old('code', $classData['code'] ?? ''), 'error' => $classErrorFor('edit', 'code'), 'attrs' => ['maxlength' => '20']]) ?>
        <?= partial('field', ['name' => 'name', 'label' => 'Nome exibido', 'required' => true, 'value' => old('name', $classData['name'] ?? ''), 'error' => $classErrorFor('edit', 'name'), 'attrs' => ['maxlength' => '100']]) ?>
      </div>
      <div class="form__row">
        <?= partial('field', ['name' => 'grade', 'label' => 'Série ou ano', 'required' => true, 'value' => old('grade', $classData['grade'] ?? ''), 'error' => $classErrorFor('edit', 'grade'), 'attrs' => ['maxlength' => '50']]) ?>
        <?= partial('field', ['name' => 'shift', 'label' => 'Turno', 'type' => 'select', 'required' => true, 'options' => $classShifts, 'value' => old('shift', $classData['shift'] ?? ''), 'error' => $classErrorFor('edit', 'shift')]) ?>
      </div>
      <p class="text-muted text-sm">O período letivo não muda depois do cadastro, porque as matrículas dependem dele.</p>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Salvar alterações</button>
      </div>
    </form>
  </details>

  <?php if ($classActive): ?>
    <section class="card card--flat" aria-labelledby="class-archive-title">
      <h2 class="card__title" id="class-archive-title">Arquivar turma</h2>
      <p class="text-muted text-sm">A turma sai das listas de turmas ativas, mas nada é apagado. Só é possível arquivar uma turma sem alunos matriculados.</p>
      <form method="post" action="<?= e(url('/classes/' . $classId . '/archive')) ?>" data-confirm="Arquivar a turma <?= e($classData['code'] ?? '') ?>?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--danger">Arquivar turma</button>
      </form>
    </section>
  <?php endif; ?>
<?php endif; ?>
