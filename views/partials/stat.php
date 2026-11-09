<?php
declare(strict_types=1);

/**
 * Cartão de indicador (rótulo + valor + observação).
 *
 * @var string           $label    rótulo curto (ex.: "Ausências no período")
 * @var string|int|float $value    valor principal
 * @var string|null      $note     observação abaixo do valor
 * @var string|null      $accent   blue (padrão) | green | purple | amber | red
 * @var bool|null        $compact  versão sem sombra, para uso dentro de outro cartão
 */
$statLabel = isset($label) && is_string($label) ? $label : '';
$statValue = isset($value) && is_scalar($value) ? (string)$value : '—';
$statNote = isset($note) && is_string($note) && $note !== '' ? $note : null;
$statAccent = isset($accent) && in_array($accent, ['green', 'purple', 'amber', 'red'], true) ? ' stat--' . $accent : '';
$statCompact = !empty($compact) ? ' stat--compact' : '';
?>
<div class="stat<?= $statAccent ?><?= $statCompact ?>">
  <span class="stat__label"><?= e($statLabel) ?></span>
  <span class="stat__value"><?= e($statValue) ?></span>
  <?php if ($statNote !== null): ?>
    <span class="stat__note"><?= e($statNote) ?></span>
  <?php endif; ?>
</div>
