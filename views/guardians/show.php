<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Ficha do responsável (issue #39): contato, situação do e-mail, alunos vinculados, edição e arquivamento.
 *
 * @var array<string, mixed>        $guardian
 * @var list<array<string, mixed>>  $students
 * @var array<string, string>       $emailStatusOptions
 * @var array<string, string>|null  $errors
 */
$gd = isset($guardian) && is_array($guardian) ? $guardian : [];
$gdId = (string)($gd['id'] ?? '');
$gdActive = !empty($gd['active']);
$gdStudents = isset($students) && is_array($students) ? $students : [];
$gdStatuses = isset($emailStatusOptions) && is_array($emailStatusOptions) ? $emailStatusOptions : [];
$gdCanEdit = can(Permissions::REGISTRY_EDIT);
$gdHasErrors = isset($errors) && is_array($errors) && $errors !== [];
?>
<p><a class="link-action" href="<?= e(url('/guardians')) ?>"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Voltar aos responsáveis</a></p>

<?php if (!$gdActive): ?>
  <div class="alert alert--warning" role="status">
    <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
    <div class="alert__body">
      <span class="alert__title">Cadastro arquivado</span>
      Este responsável não recebe avisos de ausência e não aparece na busca de vínculos. Os vínculos existentes ficam registrados.
    </div>
  </div>
<?php endif; ?>

<section class="card" aria-labelledby="guardian-data-title">
  <div class="card__header">
    <h2 class="card__title" id="guardian-data-title">Contato</h2>
    <?= partial('registry_status', ['active' => $gdActive]) ?>
  </div>
  <dl class="kv">
    <dt>E-mail</dt><dd><?= e($gd['email'] ?? '—') ?></dd>
    <dt>Situação do e-mail</dt><dd><?= badge('email_status', $gd['email_status'] ?? null) ?></dd>
    <dt>Telefone</dt><dd><?= e(($gd['phone'] ?? '') !== '' ? $gd['phone'] : '—') ?></dd>
  </dl>
</section>

<section aria-labelledby="guardian-students-title" class="stack">
  <div class="section__header">
    <h2 id="guardian-students-title">Alunos vinculados</h2>
    <p class="section__subtitle">Para vincular este responsável a outro aluno, abra a ficha do aluno.</p>
  </div>
  <?php if ($gdStudents === []): ?>
    <?= partial('empty_state', ['icon' => 'students', 'title' => 'Nenhum aluno vinculado', 'text' => 'Abra a ficha do aluno e use "Vincular responsável".', 'action_href' => '/students', 'action_label' => 'Ir para alunos']) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="guardian-students-title" tabindex="0">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Aluno</th>
            <th scope="col">Turma atual</th>
            <th scope="col">Parentesco</th>
            <th scope="col">Vínculo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($gdStudents as $student): ?>
            <tr>
              <th scope="row">
                <a href="<?= e(url('/students/' . $student['id'])) ?>"><?= e($student['name']) ?></a>
                <span class="text-muted"><?= e($student['student_code']) ?></span>
                <?php if (!$student['active']): ?><span class="badge badge--neutral">Arquivado</span><?php endif; ?>
              </th>
              <td><?= e($student['class_code'] !== null ? $student['class_code'] . ' — ' . $student['class_name'] : 'Sem turma') ?></td>
              <td><?= e(label('relationship', $student['relationship'])) ?></td>
              <td>
                <div class="cluster">
                  <?php if ($student['is_primary']): ?><span class="badge badge--accent">Principal</span><?php endif; ?>
                  <?php if ($student['receives_absence_notifications']): ?>
                    <span class="badge badge--info">Recebe avisos</span>
                  <?php else: ?>
                    <span class="badge badge--neutral">Não recebe avisos</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($gdCanEdit): ?>
  <details class="card registry-disclosure"<?= $gdHasErrors ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'guardians']) ?>
      <span>Editar contato</span>
    </summary>
    <form method="post" action="<?= e(url('/guardians/' . $gdId)) ?>" class="form registry-disclosure__body">
      <?= csrf_field() ?>
      <?php if ($gdHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <?= partial('field', ['name' => 'name', 'label' => 'Nome completo', 'required' => true, 'value' => old('name', $gd['name'] ?? ''), 'attrs' => ['maxlength' => '120']]) ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'value' => old('email', $gd['email'] ?? ''), 'attrs' => ['maxlength' => '254']]) ?>
        <?= partial('field', ['name' => 'phone', 'label' => 'Telefone com DDD', 'type' => 'tel', 'required' => true, 'value' => old('phone', $gd['phone'] ?? ''), 'attrs' => ['maxlength' => '30', 'inputmode' => 'tel']]) ?>
      </div>
      <?= partial('field', [
          'name' => 'email_status', 'label' => 'Situação do e-mail', 'type' => 'select', 'required' => true,
          'options' => $gdStatuses, 'value' => old('email_status', $gd['email_status'] ?? 'VALID'),
          'hint' => 'Use "Retornou com erro" quando o e-mail voltar; só e-mails válidos recebem os avisos.',
      ]) ?>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Salvar alterações</button>
      </div>
    </form>
  </details>

  <section class="card card--flat" aria-labelledby="guardian-status-title">
    <h2 class="card__title" id="guardian-status-title"><?= $gdActive ? 'Arquivar cadastro' : 'Reativar cadastro' ?></h2>
    <?php if ($gdActive): ?>
      <p class="text-muted text-sm">O responsável deixa de receber avisos e sai da busca de vínculos. Nada é apagado.</p>
      <form method="post" action="<?= e(url('/guardians/' . $gdId . '/archive')) ?>" data-confirm="Arquivar o cadastro de <?= e($gd['name'] ?? '') ?>?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--danger">Arquivar responsável</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= e(url('/guardians/' . $gdId . '/reactivate')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--secondary">Reativar responsável</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>
