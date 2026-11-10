<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Ficha do aluno (issues #38, #39 e #41): dados, turma atual e troca de turma, responsáveis vinculados (com edição e
 * remoção do vínculo), vínculo com responsável existente (busca) ou novo, histórico de matrículas, edição e arquivamento.
 *
 * @var array<string, mixed>            $student
 * @var list<array<string, mixed>>      $guardians
 * @var list<array<string, mixed>>      $history
 * @var list<array<string, mixed>>|null $candidates     responsáveis encontrados para vincular (null = sem busca)
 * @var string                          $guardianTerm
 * @var array<string, string>           $classOptions   turmas do período ativo
 * @var array<string, string>           $relationshipOptions
 * @var int                             $maxGuardians
 * @var string|null                     $activeForm     edit | enrollment | link-existing | link-new | link-<id>
 */
$st = isset($student) && is_array($student) ? $student : [];
$stId = (string)($st['id'] ?? '');
$stActive = !empty($st['active']);
$stGuardians = isset($guardians) && is_array($guardians) ? $guardians : [];
$stHistory = isset($history) && is_array($history) ? $history : [];
$stCandidates = isset($candidates) && is_array($candidates) ? $candidates : null;
$stTerm = isset($guardianTerm) && is_string($guardianTerm) ? $guardianTerm : '';
$stClasses = isset($classOptions) && is_array($classOptions) ? $classOptions : [];
$stRelationships = isset($relationshipOptions) && is_array($relationshipOptions) ? $relationshipOptions : [];
$stMax = isset($maxGuardians) && is_int($maxGuardians) ? $maxGuardians : 3;
$stForm = isset($activeForm) && is_string($activeForm) ? $activeForm : null;
$stCanEdit = can(Permissions::REGISTRY_EDIT);
$stRoom = count($stGuardians) < $stMax;
$stClass = is_array($st['class'] ?? null) ? $st['class'] : null;

/** Mensagem do campo somente no formulário que voltou com erro ('' suprime o erro nos demais). */
$errFor = static fn (string $form, string $field): string => $stForm === $form ? (string)(field_error($field) ?? '') : '';
/** Valor digitado somente no formulário que voltou com erro. */
$oldFor = static fn (string $form, string $field, string $default = ''): string => $stForm === $form ? (string)(is_scalar(old($field, $default)) ? old($field, $default) : $default) : $default;
/** Caixa de seleção: valor digitado no formulário com erro; senão, o padrão. */
$checkedFor = static fn (string $form, string $field, bool $default): bool => $stForm === $form ? in_array((string)(is_scalar(old($field, '')) ? old($field, '') : ''), ['1', 'on', 'true'], true) : $default;
?>
<p><a class="link-action" href="<?= e(url('/students')) ?>"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Voltar aos alunos</a></p>

<?php if (!$stActive): ?>
  <div class="alert alert--warning" role="status">
    <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
    <div class="alert__body">
      <span class="alert__title">Cadastro arquivado</span>
      Este aluno não aparece nas listas de ativos nem recebe registros de ausência. O histórico continua disponível.
    </div>
  </div>
<?php endif; ?>

<section class="card" aria-labelledby="student-data-title">
  <div class="card__header">
    <h2 class="card__title" id="student-data-title">Dados do aluno</h2>
    <?= partial('registry_status', ['active' => $stActive]) ?>
  </div>
  <dl class="kv">
    <dt>Matrícula escolar</dt><dd><?= e($st['student_code'] ?? '—') ?></dd>
    <dt>Nascimento</dt><dd><?= e(format_date($st['birth_date'] ?? null)) ?></dd>
    <dt>Turma atual</dt>
    <dd>
      <?php if ($stClass !== null): ?>
        <a href="<?= e(url('/classes/' . $stClass['id'])) ?>"><?= e($stClass['code'] . ' — ' . $stClass['name']) ?></a>
        <span class="text-muted text-sm">(<?= e($stClass['period_name'] ?? '') ?>, desde <?= e(format_date($stClass['since'] ?? null)) ?>)</span>
      <?php else: ?>
        <span class="badge badge--warning">Sem turma</span>
      <?php endif; ?>
    </dd>
    <dt>Responsáveis</dt><dd><?= e(count($stGuardians) . ' de ' . $stMax) ?></dd>
  </dl>
</section>

<section aria-labelledby="student-guardians-title" class="stack">
  <div class="section__header">
    <h2 id="student-guardians-title">Responsáveis</h2>
    <p class="section__subtitle">Até <?= e((string)$stMax) ?> responsáveis por aluno. O principal é o primeiro contato da escola; só quem recebe avisos e tem e-mail válido recebe o e-mail de ausência.</p>
  </div>

  <?php if ($stGuardians === []): ?>
    <?= partial('empty_state', ['icon' => 'guardians', 'title' => 'Nenhum responsável vinculado', 'text' => 'Sem responsável com e-mail válido, a escola não consegue avisar sobre as ausências.']) ?>
  <?php else: ?>
    <div class="grid grid--auto">
      <?php foreach ($stGuardians as $guardian): ?>
        <?php $linkForm = 'link-' . $guardian['id']; ?>
        <article class="card card--tight registry-link">
          <div class="registry-link__head">
            <h3 class="registry-link__name"><a href="<?= e(url('/guardians/' . $guardian['id'])) ?>"><?= e($guardian['name']) ?></a></h3>
            <span class="text-muted text-sm"><?= e(label('relationship', $guardian['relationship'])) ?></span>
          </div>
          <div class="cluster registry-link__badges">
            <?php if ($guardian['is_primary']): ?><span class="badge badge--accent">Principal</span><?php endif; ?>
            <?php if ($guardian['receives_absence_notifications']): ?>
              <span class="badge badge--info">Recebe avisos</span>
            <?php else: ?>
              <span class="badge badge--neutral">Não recebe avisos</span>
            <?php endif; ?>
            <?php if (!$guardian['active']): ?><span class="badge badge--neutral">Arquivado</span><?php endif; ?>
          </div>
          <dl class="kv registry-link__contact">
            <dt>E-mail</dt><dd><?= e($guardian['email'] !== '' ? $guardian['email'] : '—') ?></dd>
            <dt>Situação do e-mail</dt><dd><?= badge('email_status', $guardian['email_status']) ?></dd>
            <dt>Telefone</dt><dd><?= e($guardian['phone'] !== '' ? $guardian['phone'] : '—') ?></dd>
          </dl>
          <?php if ($stCanEdit): ?>
            <details class="registry-disclosure registry-disclosure--inline"<?= $stForm === $linkForm ? ' open' : '' ?>>
              <summary class="registry-disclosure__summary">Editar vínculo</summary>
              <form method="post" action="<?= e(url('/students/' . $stId . '/guardians/' . $guardian['id'])) ?>" class="form registry-disclosure__body">
                <?= csrf_field() ?>
                <?= partial('field', [
                    'name' => 'relationship', 'id' => 'relationship-' . $guardian['id'], 'label' => 'Parentesco', 'type' => 'select', 'required' => true,
                    'options' => $stRelationships, 'value' => $oldFor($linkForm, 'relationship', $guardian['relationship']), 'error' => $errFor($linkForm, 'relationship'),
                ]) ?>
                <label class="check">
                  <input type="checkbox" name="is_primary" value="1"<?= $checkedFor($linkForm, 'is_primary', $guardian['is_primary']) ? ' checked' : '' ?>>
                  <span>Responsável principal (desmarca o principal atual)</span>
                </label>
                <input type="hidden" name="receives_absence_notifications" value="0">
                <label class="check">
                  <input type="checkbox" name="receives_absence_notifications" value="1"<?= $checkedFor($linkForm, 'receives_absence_notifications', $guardian['receives_absence_notifications']) ? ' checked' : '' ?>>
                  <span>Recebe os avisos de ausência por e-mail</span>
                </label>
                <div class="form__actions">
                  <button type="submit" class="btn btn--primary btn--sm">Salvar vínculo</button>
                </div>
              </form>
              <form method="post" action="<?= e(url('/students/' . $stId . '/guardians/' . $guardian['id'] . '/remove')) ?>" class="registry-disclosure__body" data-confirm="Remover o vínculo com <?= e($guardian['name']) ?>? O cadastro do responsável continua existindo.">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn--quiet btn--sm">Remover vínculo</button>
              </form>
            </details>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($stCanEdit): ?>
    <?php if (!$stRoom): ?>
      <p class="text-muted text-sm">O aluno já tem <?= e((string)$stMax) ?> responsáveis. Remova um vínculo para adicionar outro.</p>
    <?php else: ?>
      <section class="card" aria-labelledby="link-title">
        <div class="card__header">
          <div>
            <h3 class="card__title" id="link-title">Vincular responsável</h3>
            <p class="card__subtitle">Procure um responsável já cadastrado (por exemplo, de um irmão) ou cadastre um novo.</p>
          </div>
        </div>
        <?php $limitError = $errFor('link-existing', 'guardians') !== '' ? $errFor('link-existing', 'guardians') : $errFor('link-new', 'guardians'); ?>
        <?php if ($limitError !== ''): ?><?= partial('registry_form_alert', ['message' => $limitError]) ?><?php endif; ?>

        <div class="stack">
          <form method="get" action="<?= e(url('/students/' . $stId)) ?>" class="cluster registry-search" role="search" aria-label="Buscar responsável já cadastrado">
            <div class="field grow">
              <label class="field__label" for="guardian_q">Nome ou e-mail do responsável já cadastrado</label>
              <input class="input" type="search" id="guardian_q" name="guardian_q" value="<?= e($stTerm) ?>" maxlength="100">
            </div>
            <button type="submit" class="btn btn--secondary"><?= partial('icon', ['name' => 'search']) ?><span>Buscar</span></button>
          </form>

          <?php if ($stForm === 'link-existing' && $errFor('link-existing', 'guardian_id') !== ''): ?>
            <?= partial('registry_form_alert', ['message' => $errFor('link-existing', 'guardian_id')]) ?>
          <?php endif; ?>

          <?php if ($stCandidates !== null): ?>
            <?php if ($stCandidates === []): ?>
              <p class="text-muted">Nenhum responsável ativo encontrado para "<?= e($stTerm) ?>". Cadastre um novo abaixo.</p>
            <?php else: ?>
              <form method="post" action="<?= e(url('/students/' . $stId . '/guardians')) ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="mode" value="existing">
                <fieldset class="registry-choices">
                  <legend class="field__label">Selecione o responsável</legend>
                  <?php foreach ($stCandidates as $index => $candidate): ?>
                    <label class="check">
                      <input type="radio" name="guardian_id" value="<?= e($candidate['id']) ?>"<?= $index === 0 ? ' required' : '' ?>>
                      <span><strong><?= e($candidate['name']) ?></strong> <span class="text-muted">· <?= e($candidate['email']) ?> · <?= e($candidate['phone']) ?></span></span>
                    </label>
                  <?php endforeach; ?>
                </fieldset>
                <?= partial('field', [
                    'name' => 'relationship', 'id' => 'existing-relationship', 'label' => 'Parentesco', 'type' => 'select', 'required' => true,
                    'options' => $stRelationships, 'placeholder' => 'Selecione o parentesco',
                    'value' => $oldFor('link-existing', 'relationship'), 'error' => $errFor('link-existing', 'relationship'),
                ]) ?>
                <label class="check">
                  <input type="checkbox" name="is_primary" value="1"<?= $checkedFor('link-existing', 'is_primary', $stGuardians === []) ? ' checked' : '' ?>>
                  <span>Responsável principal</span>
                </label>
                <input type="hidden" name="receives_absence_notifications" value="0">
                <label class="check">
                  <input type="checkbox" name="receives_absence_notifications" value="1"<?= $checkedFor('link-existing', 'receives_absence_notifications', true) ? ' checked' : '' ?>>
                  <span>Recebe os avisos de ausência por e-mail</span>
                </label>
                <div class="form__actions">
                  <button type="submit" class="btn btn--primary">Vincular responsável</button>
                </div>
              </form>
            <?php endif; ?>
          <?php endif; ?>

          <details class="registry-disclosure registry-disclosure--inline"<?= $stForm === 'link-new' ? ' open' : '' ?>>
            <summary class="registry-disclosure__summary"><?= partial('icon', ['name' => 'plus']) ?> <span>Cadastrar novo responsável e vincular</span></summary>
            <form method="post" action="<?= e(url('/students/' . $stId . '/guardians')) ?>" class="form registry-disclosure__body">
              <?= csrf_field() ?>
              <input type="hidden" name="mode" value="new">
              <?php if ($stForm === 'link-new'): ?><?= partial('registry_form_alert') ?><?php endif; ?>
              <?= partial('field', ['name' => 'guardian_name', 'label' => 'Nome completo', 'required' => true, 'value' => $oldFor('link-new', 'guardian_name'), 'error' => $errFor('link-new', 'guardian_name'), 'attrs' => ['maxlength' => '120']]) ?>
              <div class="form__row">
                <?= partial('field', ['name' => 'guardian_email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'autocomplete' => 'off', 'value' => $oldFor('link-new', 'guardian_email'), 'error' => $errFor('link-new', 'guardian_email'), 'attrs' => ['maxlength' => '254']]) ?>
                <?= partial('field', ['name' => 'guardian_phone', 'label' => 'Telefone com DDD', 'type' => 'tel', 'required' => true, 'hint' => 'Por exemplo: (11) 99999-0001.', 'value' => $oldFor('link-new', 'guardian_phone'), 'error' => $errFor('link-new', 'guardian_phone'), 'attrs' => ['maxlength' => '30', 'inputmode' => 'tel']]) ?>
              </div>
              <?= partial('field', [
                  'name' => 'relationship', 'id' => 'new-relationship', 'label' => 'Parentesco', 'type' => 'select', 'required' => true,
                  'options' => $stRelationships, 'placeholder' => 'Selecione o parentesco',
                  'value' => $oldFor('link-new', 'relationship'), 'error' => $errFor('link-new', 'relationship'),
              ]) ?>
              <label class="check">
                <input type="checkbox" name="is_primary" value="1"<?= $checkedFor('link-new', 'is_primary', $stGuardians === []) ? ' checked' : '' ?>>
                <span>Responsável principal</span>
              </label>
              <input type="hidden" name="receives_absence_notifications" value="0">
              <label class="check">
                <input type="checkbox" name="receives_absence_notifications" value="1"<?= $checkedFor('link-new', 'receives_absence_notifications', true) ? ' checked' : '' ?>>
                <span>Recebe os avisos de ausência por e-mail</span>
              </label>
              <div class="form__actions">
                <button type="submit" class="btn btn--primary">Cadastrar e vincular</button>
              </div>
            </form>
          </details>
        </div>
      </section>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($stCanEdit && $stActive): ?>
  <section class="card" aria-labelledby="enrollment-title">
    <h2 class="card__title" id="enrollment-title"><?= $stClass !== null ? 'Trocar de turma' : 'Matricular em uma turma' ?></h2>
    <p class="card__subtitle">
      <?= $stClass !== null
          ? 'A matrícula atual é encerrada com o motivo "mudança de turma" e continua no histórico; as ausências já registradas mantêm a turma original.'
          : 'O aluno está sem turma. Escolha uma turma do período letivo ativo.' ?>
    </p>
    <?php if ($stClasses === []): ?>
      <p class="text-muted">Não há turmas ativas no período letivo ativo. <a href="<?= e(url('/classes')) ?>">Ir para turmas</a></p>
    <?php else: ?>
      <form method="post" action="<?= e(url('/students/' . $stId . '/enrollment')) ?>" class="form">
        <?= csrf_field() ?>
        <?= partial('field', [
            'name' => 'class_id', 'id' => 'enrollment-class', 'label' => 'Nova turma', 'type' => 'select', 'required' => true,
            'options' => $stClasses, 'placeholder' => 'Selecione a turma',
            'value' => $oldFor('enrollment', 'class_id'), 'error' => $errFor('enrollment', 'class_id'),
        ]) ?>
        <div class="form__actions">
          <button type="submit" class="btn btn--primary"><?= $stClass !== null ? 'Trocar de turma' : 'Matricular' ?></button>
        </div>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section aria-labelledby="history-title" class="stack">
  <div class="section__header">
    <h2 id="history-title">Histórico de matrículas</h2>
    <p class="section__subtitle">Da mais recente para a mais antiga. Matrículas encerradas nunca são apagadas.</p>
  </div>
  <?php if ($stHistory === []): ?>
    <?= partial('empty_state', ['icon' => 'classes', 'title' => 'Nenhuma matrícula registrada']) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="history-title" tabindex="0">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Turma</th>
            <th scope="col">Período</th>
            <th scope="col">Início</th>
            <th scope="col">Fim</th>
            <th scope="col">Situação</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stHistory as $item): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/classes/' . $item['class_id'])) ?>"><?= e($item['class_code']) ?></a> <span class="text-muted"><?= e($item['class_name']) ?></span></th>
              <td><?= e($item['period_name']) ?></td>
              <td><?= e(format_date($item['start_date'])) ?></td>
              <td><?= e(format_date($item['end_date'])) ?></td>
              <td>
                <?php if ($item['active']): ?>
                  <span class="badge badge--success">Vigente</span>
                <?php else: ?>
                  <span class="badge badge--neutral">Encerrada: <?= e($item['end_reason_label'] ?? '—') ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($stCanEdit): ?>
  <details class="card registry-disclosure"<?= $stForm === 'edit' ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'students']) ?>
      <span>Editar dados do aluno</span>
    </summary>
    <form method="post" action="<?= e(url('/students/' . $stId)) ?>" class="form registry-disclosure__body">
      <?= csrf_field() ?>
      <?php if ($stForm === 'edit'): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <?= partial('field', ['name' => 'name', 'label' => 'Nome completo', 'required' => true, 'value' => $oldFor('edit', 'name', (string)($st['name'] ?? '')), 'error' => $errFor('edit', 'name'), 'attrs' => ['maxlength' => '120']]) ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'student_code', 'label' => 'Matrícula escolar (RA)', 'required' => true, 'value' => $oldFor('edit', 'student_code', (string)($st['student_code'] ?? '')), 'error' => $errFor('edit', 'student_code'), 'attrs' => ['maxlength' => '30']]) ?>
        <?= partial('field', ['name' => 'birth_date', 'label' => 'Data de nascimento', 'type' => 'date', 'required' => true, 'value' => $oldFor('edit', 'birth_date', (string)($st['birth_date'] ?? '')), 'error' => $errFor('edit', 'birth_date')]) ?>
      </div>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Salvar alterações</button>
      </div>
    </form>
  </details>

  <section class="card card--flat" aria-labelledby="student-status-title">
    <h2 class="card__title" id="student-status-title"><?= $stActive ? 'Arquivar cadastro' : 'Reativar cadastro' ?></h2>
    <?php if ($stActive): ?>
      <p class="text-muted text-sm">O aluno sai das listas e a matrícula atual é encerrada (transferência). Nada é apagado: ausências e histórico continuam disponíveis.</p>
      <form method="post" action="<?= e(url('/students/' . $stId . '/archive')) ?>" data-confirm="Arquivar o cadastro de <?= e($st['name'] ?? '') ?>?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--danger">Arquivar aluno</button>
      </form>
    <?php else: ?>
      <p class="text-muted text-sm">O cadastro volta para a lista de ativos. Depois, matricule o aluno em uma turma.</p>
      <form method="post" action="<?= e(url('/students/' . $stId . '/reactivate')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--secondary">Reativar aluno</button>
      </form>
    <?php endif; ?>
  </section>
<?php endif; ?>
