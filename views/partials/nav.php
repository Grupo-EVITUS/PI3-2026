<?php
declare(strict_types=1);

/**
 * Menu lateral. nav_items() já devolve só o que o perfil pode usar e que existe como rota,
 * com o item atual marcado em 'active'.
 *
 * @var string|null $activeNav  caminho do item ativo (opcional; sobrepõe o 'active' de nav_items())
 */
$navActive = isset($activeNav) && is_string($activeNav) && $activeNav !== '' ? $activeNav : null;
?>
<nav class="sidebar__nav" aria-label="Navegação principal">
  <ul class="nav">
    <?php foreach (nav_items() as $navItem): ?>
      <?php $navIsActive = $navActive !== null ? (($navItem['path'] ?? '') === $navActive) : !empty($navItem['active']); ?>
      <li>
        <a class="nav__link<?= $navIsActive ? ' is-active' : '' ?>" href="<?= e(url((string)($navItem['path'] ?? '/'))) ?>"<?= $navIsActive ? ' aria-current="page"' : '' ?>>
          <?= partial('icon', ['name' => (string)($navItem['icon'] ?? 'home')]) ?>
          <span><?= e($navItem['label'] ?? '') ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</nav>
