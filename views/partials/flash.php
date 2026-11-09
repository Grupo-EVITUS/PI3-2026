<?php
declare(strict_types=1);

/**
 * Mensagens flash (consome flash_messages(): cada mensagem aparece uma única vez).
 * Tipos: success | error | info | warning. Erros usam role="alert"; os demais, role="status".
 */
$flashList = flash_messages();
$flashIcons = ['success' => 'check', 'error' => 'alert', 'warning' => 'alert', 'info' => 'info'];
?>
<?php if (is_array($flashList) && $flashList !== []): ?>
<div class="flash">
  <?php foreach ($flashList as $flash): ?>
    <?php $flashKind = isset($flash['kind'], $flashIcons[$flash['kind']]) ? (string)$flash['kind'] : 'info'; ?>
    <div class="alert alert--<?= e($flashKind) ?>" role="<?= $flashKind === 'error' ? 'alert' : 'status' ?>">
      <?= partial('icon', ['name' => $flashIcons[$flashKind], 'class' => 'alert__icon']) ?>
      <div class="alert__body"><?= e($flash['message'] ?? '') ?></div>
      <button type="button" class="alert__close" data-dismiss hidden>
        <?= partial('icon', ['name' => 'close', 'class' => 'icon--sm']) ?>
        <span class="sr-only">Fechar mensagem</span>
      </button>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
