<?php
declare(strict_types=1);

/**
 * Paginação acessível (nav + lista). Não imprime nada quando há uma página só.
 *
 * @var int                  $page   página atual (1..$pages)
 * @var int                  $pages  total de páginas
 * @var string               $base   caminho da listagem (ex.: "/students")
 * @var array<string,scalar> $query  parâmetros a preservar (filtros); "page" é calculado aqui
 */
$pagerPages = isset($pages) && is_int($pages) ? $pages : 1;
$pagerPage = isset($page) && is_int($page) ? max(1, min($page, max(1, $pagerPages))) : 1;
$pagerBase = isset($base) && is_string($base) ? $base : '/';
$pagerQuery = isset($query) && is_array($query) ? $query : [];
unset($pagerQuery['page']);

$pagerLink = static fn (int $n): string => url($pagerBase, $n > 1 ? $pagerQuery + ['page' => $n] : $pagerQuery);

// Janela: primeira, última, atual e vizinhas; lacunas viram reticências.
$pagerShown = [];
for ($n = 1; $n <= $pagerPages; $n++) {
    if ($n === 1 || $n === $pagerPages || abs($n - $pagerPage) <= 1) {
        $pagerShown[] = $n;
    }
}
?>
<?php if ($pagerPages > 1): ?>
<nav class="pagination" aria-label="Paginação">
  <ul class="pagination__list">
    <li>
      <?php if ($pagerPage > 1): ?>
        <a class="pagination__link" href="<?= e($pagerLink($pagerPage - 1)) ?>" rel="prev"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Anterior</a>
      <?php else: ?>
        <span class="pagination__link is-disabled" aria-hidden="true"><?= partial('icon', ['name' => 'chevron-left', 'class' => 'icon--sm']) ?> Anterior</span>
      <?php endif; ?>
    </li>
    <?php $pagerLast = 0; ?>
    <?php foreach ($pagerShown as $n): ?>
      <?php if ($pagerLast !== 0 && $n - $pagerLast > 1): ?>
        <li><span class="pagination__gap" aria-hidden="true">…</span></li>
      <?php endif; ?>
      <li>
        <?php if ($n === $pagerPage): ?>
          <a class="pagination__link" href="<?= e($pagerLink($n)) ?>" aria-current="page"><span class="sr-only">Página </span><?= $n ?></a>
        <?php else: ?>
          <a class="pagination__link" href="<?= e($pagerLink($n)) ?>"><span class="sr-only">Página </span><?= $n ?></a>
        <?php endif; ?>
      </li>
      <?php $pagerLast = $n; ?>
    <?php endforeach; ?>
    <li>
      <?php if ($pagerPage < $pagerPages): ?>
        <a class="pagination__link" href="<?= e($pagerLink($pagerPage + 1)) ?>" rel="next">Próxima <?= partial('icon', ['name' => 'chevron-right', 'class' => 'icon--sm']) ?></a>
      <?php else: ?>
        <span class="pagination__link is-disabled" aria-hidden="true">Próxima <?= partial('icon', ['name' => 'chevron-right', 'class' => 'icon--sm']) ?></span>
      <?php endif; ?>
    </li>
  </ul>
</nav>
<?php endif; ?>
