<?php
declare(strict_types=1);

/**
 * Estado vazio amigável: ícone, título, explicação e (opcionalmente) uma ação.
 *
 * @var string      $title         título (obrigatório)
 * @var string|null $text          explicação curta
 * @var string|null $icon          ícone do sprite (padrão: info)
 * @var int|null    $level         nível do título (2-4; padrão 3)
 * @var string|null $action_href   caminho da ação principal
 * @var string|null $action_label  texto da ação principal
 */
$emptyTitle = isset($title) && is_string($title) ? $title : '';
$emptyText = isset($text) && is_string($text) && $text !== '' ? $text : null;
$emptyIcon = isset($icon) && is_string($icon) && $icon !== '' ? $icon : 'info';
$emptyLevel = isset($level) && is_int($level) && $level >= 2 && $level <= 4 ? $level : 3;
$emptyHref = isset($action_href) && is_string($action_href) && $action_href !== '' ? $action_href : null;
$emptyLabel = isset($action_label) && is_string($action_label) && $action_label !== '' ? $action_label : null;
?>
<div class="empty">
  <span class="empty__icon" aria-hidden="true"><?= partial('icon', ['name' => $emptyIcon, 'class' => 'icon--lg']) ?></span>
  <h<?= $emptyLevel ?> class="empty__title"><?= e($emptyTitle) ?></h<?= $emptyLevel ?>>
  <?php if ($emptyText !== null): ?>
    <p class="empty__text"><?= e($emptyText) ?></p>
  <?php endif; ?>
  <?php if ($emptyHref !== null && $emptyLabel !== null): ?>
    <div class="empty__actions">
      <a class="btn btn--primary" href="<?= e(url($emptyHref)) ?>"><?= e($emptyLabel) ?></a>
    </div>
  <?php endif; ?>
</div>
