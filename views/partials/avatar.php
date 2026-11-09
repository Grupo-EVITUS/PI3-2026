<?php
declare(strict_types=1);

/**
 * Avatar com a inicial do nome (decorativo: o nome aparece sempre em texto ao lado).
 *
 * @var string|null $name  nome completo
 * @var string|null $size  "lg" para a versão grande
 */
$avatarName = isset($name) && is_string($name) ? trim($name) : '';
$avatarInitial = $avatarName !== '' ? mb_strtoupper(mb_substr($avatarName, 0, 1, 'UTF-8'), 'UTF-8') : '?';
$avatarSize = isset($size) && $size === 'lg' ? ' avatar--lg' : '';

echo '<span class="avatar', $avatarSize, '" aria-hidden="true">', e($avatarInitial), '</span>';
