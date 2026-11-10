<?php
declare(strict_types=1);

use Saa\Security\Permissions;

/**
 * Períodos letivos: lista (com o período ativo em destaque) e formulário de cadastro.
 *
 * @var list<array<string, mixed>> $periods
 * @var array<string, string>|null $errors
 * @var bool|null                  $openForm  abre o formulário (após erro de validação)
 */
$periodList = isset($periods) && is_array($periods) ? $periods : [];
$periodCanEdit = can(Permissions::REGISTRY_EDIT);
$periodFormOpen = !empty($openForm) || ($periodCanEdit && $periodList === []);
$periodHasErrors = isset($errors) && is_array($errors) && $errors !== [];
?>
<section aria-labelledby="periods-title">
  <div class="section__header">
    <h2 id="periods-title">Períodos cadastrados</h2>
    <p class="section__subtitle">O período ativo é o ano letivo vigente: as turmas dele recebem matrículas e a frequência mínima vale para os alertas.</p>
  </div>

  <?php if ($periodList === []): ?>
    <?= partial('empty_state', [
        'icon' => 'classes',
        'title' => 'Nenhum período letivo cadastrado',
        'text' => $periodCanEdit ? 'Cadastre o primeiro período abaixo. Ele já ficará ativo.' : 'Peça à secretaria para cadastrar o período letivo.',
    ]) ?>
  <?php else: ?>
    <div class="table-wrap" role="region" aria-labelledby="periods-title" tabindex="0">
      <table class="table">
        <caption class="sr-only">Períodos letivos com datas, regra de frequência e situação</caption>
        <thead>
          <tr>
            <th scope="col">Período</th>
            <th scope="col">Início</th>
            <th scope="col">Término</th>
            <th scope="col" class="num">Frequência mínima</th>
            <th scope="col" class="num">Alerta abaixo de</th>
            <th scope="col">Situação</th>
            <th scope="col"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periodList as $period): ?>
            <tr>
              <th scope="row"><a href="<?= e(url('/periods/' . $period['id'])) ?>"><?= e($period['name']) ?></a></th>
              <td><?= e(format_date($period['start_date'])) ?></td>
              <td><?= e(format_date($period['end_date'])) ?></td>
              <td class="num"><?= e((string)$period['min_attendance_percent']) ?>%</td>
              <td class="num"><?= e((string)$period['alert_below_percent']) ?>%</td>
              <td><?= partial('registry_status', ['active' => $period['active'], 'active_label' => 'Ativo', 'off_label' => 'Inativo']) ?></td>
              <td>
                <?php if ($periodCanEdit && !$period['active']): ?>
                  <form method="post" action="<?= e(url('/periods/' . $period['id'] . '/activate')) ?>" data-confirm="Tornar o período <?= e($period['name']) ?> o período ativo? O período ativo atual deixará de ser o vigente.">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn--secondary btn--sm">Tornar ativo</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($periodCanEdit): ?>
  <details class="card registry-disclosure"<?= $periodFormOpen ? ' open' : '' ?>>
    <summary class="registry-disclosure__summary">
      <?= partial('icon', ['name' => 'plus']) ?>
      <span>Cadastrar período letivo</span>
    </summary>
    <form method="post" action="<?= e(url('/periods')) ?>" class="form registry-disclosure__body">
      <?= csrf_field() ?>
      <?php if ($periodHasErrors): ?><?= partial('registry_form_alert') ?><?php endif; ?>
      <?= partial('field', ['name' => 'name', 'label' => 'Nome do período', 'required' => true, 'hint' => 'Por exemplo: 2026.', 'attrs' => ['maxlength' => '50']]) ?>
      <div class="form__row">
        <?= partial('field', ['name' => 'start_date', 'label' => 'Início das aulas', 'type' => 'date', 'required' => true]) ?>
        <?= partial('field', ['name' => 'end_date', 'label' => 'Término das aulas', 'type' => 'date', 'required' => true]) ?>
      </div>
      <div class="form__row">
        <?= partial('field', ['name' => 'min_attendance_percent', 'label' => 'Frequência mínima (%)', 'type' => 'number', 'required' => true, 'value' => old('min_attendance_percent', '75'), 'attrs' => ['min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'numeric']]) ?>
        <?= partial('field', ['name' => 'alert_below_percent', 'label' => 'Alertar abaixo de (%)', 'type' => 'number', 'required' => true, 'value' => old('alert_below_percent', '80'), 'hint' => 'Não pode ser menor que a frequência mínima.', 'attrs' => ['min' => '0', 'max' => '100', 'step' => '1', 'inputmode' => 'numeric']]) ?>
      </div>
      <div class="form__actions">
        <button type="submit" class="btn btn--primary">Cadastrar período</button>
      </div>
    </form>
  </details>
<?php endif; ?>
