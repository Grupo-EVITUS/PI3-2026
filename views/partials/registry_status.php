<?php
declare(strict_types=1);

/**
 * Selo de situação de um cadastro (aluno, responsável, turma ou período).
 *
 * @var bool        $active        cadastro ativo?
 * @var string|null $active_label  texto quando ativo (padrão: "Ativo")
 * @var string|null $off_label     texto quando inativo (padrão: "Arquivado")
 */
$statusActive = !empty($active);
$statusOn = isset($active_label) && is_string($active_label) && $active_label !== '' ? $active_label : 'Ativo';
$statusOff = isset($off_label) && is_string($off_label) && $off_label !== '' ? $off_label : 'Arquivado';
?>
<span class="badge <?= $statusActive ? 'badge--success' : 'badge--neutral' ?>"><?= e($statusActive ? $statusOn : $statusOff) ?></span>
