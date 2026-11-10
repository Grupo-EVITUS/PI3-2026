<?php
declare(strict_types=1);

/**
 * Aviso no topo de um formulário de cadastro que voltou com erro (422). As mensagens de cada campo aparecem
 * junto do campo (parcial field); aqui fica só o resumo e, se houver, uma mensagem geral.
 *
 * @var string|null $message  mensagem geral (padrão: "Verifique os campos destacados.")
 */
$alertMessage = isset($message) && is_string($message) && $message !== '' ? $message : 'Verifique os campos destacados e tente novamente.';
?>
<div class="alert alert--error" role="alert">
  <?= partial('icon', ['name' => 'alert', 'class' => 'alert__icon']) ?>
  <div class="alert__body"><?= e($alertMessage) ?></div>
</div>
