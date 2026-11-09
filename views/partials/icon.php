<?php
declare(strict_types=1);

/**
 * Ícone do sprite /assets/img/icons.svg (traço de 2px, cor herdada).
 *
 * Uso: <?= partial('icon', ['name' => 'home']) ?>
 *
 * @var string      $name   id do ícone sem o prefixo "icon-" (ex.: home, alert, check)
 * @var string|null $class  classes extras (ex.: "icon--sm", "alert__icon")
 * @var string|null $label  texto acessível; sem ele o ícone é decorativo (aria-hidden)
 */
$iconName = isset($name) && is_string($name) ? preg_replace('/[^a-z0-9-]/', '', strtolower($name)) : '';
$iconClass = isset($class) && is_string($class) && $class !== '' ? ' ' . $class : '';
$iconLabel = isset($label) && is_string($label) && $label !== '' ? $label : null;

echo '<svg class="icon', e($iconClass), '"',
    $iconLabel !== null ? ' role="img" aria-label="' . e($iconLabel) . '"' : ' aria-hidden="true" focusable="false"',
    '><use href="', e(asset('img/icons.svg')), '#icon-', e((string)$iconName), '"></use></svg>';
