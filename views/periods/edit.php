<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Ficha de um período letivo: dados e formulário de edição (somente com registry.edit).
 *
 * @var array<string, mixed>       $period
 * @var array<string, string>|null $errors
 */
$periodData = isset($period) && is_array($period) ? $period : [];
$periodCanEdit = can(Permissions::REGISTRY_EDIT);
$periodHasErrors = isset($errors) && is_array($errors) && $errors !== [];
?>
<p><a class="link-action" href="<?= e(url('/periods')) ?>"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Voltar aos períodos letivos</a></p>

<section class="card" aria-labelledby="period-data-title">
  <div class="card__header">
    <h2 class="card__title" id="period-data-title">Dados do período</h2>
    <?= partial('registry_status', ['active' => $periodData['active'] ?? false, 'active_label' => 'Período ativo', 'off_label' => 'Inativo']) ?>
  </div>
  <dl class="kv">
    <dt>Início</dt><dd><?= e(format_date($periodData['start_date'] ?? null)) ?></dd>
    <dt>Término</dt><dd><?= e(format_date($periodData['end_date'] ?? null)) ?></dd>
    <dt>Frequência mínima</dt><dd><?= e((string)($periodData['min_attendance_percent'] ?? '—')) ?>%</dd>
    <dt>Alerta abaixo de</dt><dd><?= e((string)($periodData['alert_below_percent'] ?? '—')) ?>%</dd>
  </dl>
  <?php if ($periodCanEdit && empty($periodData['active'])): ?>
    <div class="card__footer">
      <form method="post" action="<?= e(url('/periods/' . ($periodData['id'] ?? '') . '/activate')) ?>" data-confirm="Tornar este o período letivo ativo?">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn--secondary">Tornar este o período ativo</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<?php if ($periodCanEdit): ?>
  <section class="card" aria-labelledby="period-edit-title">
    <h2 class="card__title" id="period-edit-title">Editar período</h2>
    <form method="post" action="<?= e(url('/periods/' . ($periodData['id'] ?? ''))) ?>" class="form">
      <?= csrf_field() ?>
      <?php if ($periodHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <?= partial('field', ['name' => 'name', 'label' => 'Nome do período', 'required' => true, 'value' => old('name', $periodData['name'] ?? ''), 'attrs' => ['maxlength' => '50']]) ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'start_date', 'label' => 'Início das aulas', 'type' => 'date', 'required' => true, 'value' => old('start_date', $periodData['start_date'] ?? '')]) ?>
        <?= partial('field', ['name' => 'end_date', 'label' => 'Término das aulas', 'type' => 'date', 'required' => true, 'value' => old('end_date', $periodData['end_date'] ?? '')]) ?>
      </div>
      <div class="form__row">
        <?= partial('field', ['name' => 'min_attendance_percent', 'label' => 'Frequência mínima (%)', 'type' => 'number', 'required' => true, 'value' => old('min_attendance_percent', (string)($periodData['min_attendance_percent'] ?? '')), 'attrs' => ['min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'numeric']]) ?>
        <?= partial('field', ['name' => 'alert_below_percent', 'label' => 'Alertar abaixo de (%)', 'type' => 'number', 'required' => true, 'value' => old('alert_below_percent', (string)($periodData['alert_below_percent'] ?? '')), 'attrs' => ['min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'numeric']]) ?>
      </div>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Salvar alterações</button>
        <a class="btn btn--quiet" href="<?= e(url('/periods')) ?>">Cancelar</a>
      </div>
    </form>
  </section>
<?php endif; ?>
