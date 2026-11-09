<?php
declare(strict_types=1);

/**
 * Marca do SAA: marca gráfica + nome + descrição.
 *
 * @var string|null $href     destino do link (ex.: "/home"); sem valor, a marca não é um link
 * @var string|null $variant  "on-dark" (menu lateral, padrão) | "on-light" (login e área pública)
 */
$brandHref = isset($href) && is_string($href) && $href !== '' ? $href : null;
$brandVariant = isset($variant) && $variant === 'on-light' ? 'on-light' : 'on-dark';
$brandTag = $brandHref !== null ? 'a' : 'span';
?>
<<?= $brandTag ?> class="brand brand--<?= e($brandVariant) ?>"<?= $brandHref !== null ? ' href="' . e(url($brandHref)) . '"' : '' ?>>
  <img class="brand__mark" src="<?= e(asset('img/logo-mark.svg')) ?>" width="40" height="40" alt="">
  <span class="brand__text">
    <strong class="brand__name">SAA</strong>
    <span class="brand__tagline">Sistema de Ausência de Alunos</span>
  </span>
</<?= $brandTag ?>>
