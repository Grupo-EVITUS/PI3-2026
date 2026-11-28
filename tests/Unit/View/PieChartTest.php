<?php
declare(strict_types=1);

use Saa\View\PieChart;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$rejects = static function (callable $build, string $message) use ($assert): void {
    try {
        $build();
    } catch (InvalidArgumentException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};
$near = static fn(float $actual, float $expected): bool => abs($actual - $expected) < 1e-9;
$attendance = static fn(int|float $present, int|float $absent): array => [
    ['label' => 'Presenças', 'value' => $present, 'color' => '#00897b'],
    ['label' => 'Ausências', 'value' => $absent, 'color' => '#d67b3a'],
];
// O SVG precisa ser XML válido por si só (também é embutido no HTML).
$parse = static function (string $markup) use ($assert): SimpleXMLElement {
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($markup);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $assert($xml instanceof SimpleXMLElement, 'marcação deve ser XML válido: ' . $markup);
    $xml->registerXPathNamespace('s', 'http://www.w3.org/2000/svg');
    return $xml;
};
$arcFlag = static function (string $path): int {
    if (preg_match('/A[\d.]+ [\d.]+ 0 ([01]) 1 [\d.]+ [\d.]+Z$/', $path, $match) !== 1) {
        throw new RuntimeException('caminho da fatia fora do formato esperado: ' . $path);
    }
    return (int)$match[1];
};

// Geometria: 45 presenças e 5 ausências.
$chart = new PieChart($attendance(45, 5));
[$present, $absent] = $chart->geometry();
$assert($chart->total() === 50, 'total deve somar as fatias');
$assert($near($present['sweep'] + $absent['sweep'], 360.0), 'soma dos ângulos deve ser 360°');
$assert($present['start'] === 0.0 && $near($present['end'], 324.0) && $absent['start'] === $present['end'],
    'fatias devem ser contíguas a partir das 12 h');
$assert($absent['end'] === 360.0, 'última fatia deve fechar em 360° exatos');
$assert($present['large_arc'] && !$absent['large_arc'], 'arco grande só acima de 180°');
$assert($present['percent'] === 90.0 && $absent['percent'] === 10.0, 'porcentagens de 45/50 e 5/50');

$svg = $chart->svg(240);
$xml = $parse($svg);
$paths = $xml->xpath('/s:svg/s:path');
$assert($xml->getName() === 'svg' && (string)$xml['viewBox'] === '0 0 240 240'
    && (string)$xml['width'] === '240' && (string)$xml['height'] === '240', 'SVG deve ter o tamanho pedido');
$assert(count($paths) === 2, 'duas fatias devem virar dois caminhos');
$assert((string)$paths[0]['d'] === 'M120 120L120 0A120 120 0 1 1 49.466 22.918Z',
    'fatia de presenças deve sair das 12 h e ir até 324°');
$assert($arcFlag((string)$paths[0]['d']) === 1 && $arcFlag((string)$paths[1]['d']) === 0,
    'atributo de arco grande deve acompanhar a abertura da fatia');
$assert((string)$paths[0]['fill'] === '#00897b' && (string)$paths[1]['fill'] === '#d67b3a', 'cores das fatias');
$assert((string)$paths[0]['stroke'] === PieChart::SURFACE && (string)$paths[0]['stroke-width'] === '2'
    && (string)$paths[1]['stroke'] === '#ffffff', 'separação de 2px na cor da superfície');

// Acessibilidade.
$assert((string)$xml['role'] === 'img', 'SVG informativo deve ter role="img"');
$assert((string)$xml['aria-label'] === 'Presenças 45 de 50 (90,0%), ausências 5 (10,0%)', 'rótulo acessível com os números');
$assert((string)$paths[0]->title === 'Presenças: 45 de 50 (90,0%)' && (string)$paths[1]->title === 'Ausências: 5 de 50 (10,0%)',
    'cada fatia deve ter dica nativa (<title>)');
$assert(!str_contains($svg, 'style=') && !preg_match('/\son[a-z]+=/i', $svg) && !str_contains($svg, '<text'),
    'SVG sem estilo embutido, sem eventos e sem texto dentro das fatias');
$decorative = $parse($chart->svg(72, decorative: true));
$assert((string)$decorative['aria-hidden'] === 'true' && !isset($decorative['role']) && !isset($decorative['aria-label']),
    'SVG decorativo fica oculto dos leitores de tela');
$assert(count($decorative->xpath('/s:svg/s:path/s:title')) === 2, 'versão decorativa mantém a dica ao passar o mouse');

// Exatamente 180°: dois semicírculos, nenhum com arco grande; um pouco acima de 180° liga o arco grande.
[$left, $right] = (new PieChart($attendance(5, 5)))->geometry();
$assert($left['sweep'] === 180.0 && !$left['large_arc'] && !$right['large_arc'], '180° não é arco grande');
$overHalf = $parse((new PieChart($attendance(181, 179)))->svg(100))->xpath('/s:svg/s:path');
$assert($arcFlag((string)$overHalf[0]['d']) === 1 && $arcFlag((string)$overHalf[1]['d']) === 0, '181° usa arco grande');

// 100% (e 0% na outra fatia): círculo cheio, nada desenhado para a fatia zerada.
$full = new PieChart($attendance(30, 0));
$fullXml = $parse($full->svg(72));
$circles = $fullXml->xpath('/s:svg/s:circle');
$assert(count($fullXml->xpath('/s:svg/s:path')) === 0 && count($circles) === 1, '100% deve virar um círculo cheio');
$assert((string)$circles[0]['fill'] === '#00897b' && (string)$circles[0]['r'] === '36'
    && (string)$circles[0]->title === 'Presenças: 30 de 30 (100,0%)', 'círculo cheio com a cor e a dica da fatia');
$assert((string)$fullXml['aria-label'] === 'Presenças 30 de 30 (100,0%), ausências 0 (0,0%)', 'fatia zerada continua no rótulo');
[, $zero] = $full->geometry();
$assert($zero['sweep'] === 0.0 && $zero['percent'] === 0.0 && !$zero['large_arc'], 'fatia de 0% não tem abertura');
$allAbsent = $parse((new PieChart($attendance(0, 12)))->svg(72))->xpath('/s:svg/s:circle');
$assert(count($allAbsent) === 1 && (string)$allAbsent[0]['fill'] === '#d67b3a', '100% de ausências usa a cor de ausências');

// Total zero: anel cinza "Sem dados".
$empty = new PieChart($attendance(0, 0));
$emptyXml = $parse($empty->svg(72));
$ring = $emptyXml->xpath('/s:svg/s:circle');
$assert((string)$emptyXml['aria-label'] === 'Sem dados' && $empty->accessibleLabel() === 'Sem dados', 'total zero é "Sem dados"');
$assert(count($ring) === 1 && (string)$ring[0]['fill'] === 'none' && (string)$ring[0]->title === 'Sem dados'
    && count($emptyXml->xpath('/s:svg/s:path')) === 0, 'total zero desenha só o anel cinza');
$assert($empty->geometry()[0]['percent'] === null && $empty->geometry()[0]['sweep'] === 0.0, 'sem total não há porcentagem');

// Mesma truncagem do relatório: 89,995% aparece como 89,9%, nunca como 90,0%.
$truncated = new PieChart($attendance(89995, 10005));
$assert($truncated->geometry()[0]['percent'] === 89.9 && $truncated->geometry()[1]['percent'] === 10.0, 'porcentagem truncada');
$assert($truncated->accessibleLabel() === 'Presenças 89.995 de 100.000 (89,9%), ausências 10.005 (10,0%)',
    'números com separador de milhar e vírgula decimal');
$assert(PieChart::percent(2, 3) === 66.6 && PieChart::percent(1, 0) === null, 'truncagem e total zero');

// Fatia quase inteira cujo arco não teria extremos distintos: círculo por baixo, fatia minúscula omitida.
$almostFull = $parse((new PieChart($attendance(10 ** 12, 1)))->svg(240));
$assert(count($almostFull->xpath('/s:svg/s:circle')) === 1 && count($almostFull->xpath('/s:svg/s:path')) === 0,
    'fatia quase inteira não pode sumir');

// Escape de texto e validade XML.
$evil = new PieChart([
    ['label' => '<script>alert("x")</script> & \'aspas\'', 'value' => 3, 'color' => '#00897B'],
    ['label' => "Outros\x01", 'value' => 1, 'color' => '#d67b3a'],
]);
$evilSvg = $evil->svg(100);
$evilXml = $parse($evilSvg);
$assert(!str_contains($evilSvg, '<script') && str_contains($evilSvg, '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &apos;aspas&apos;'),
    'rótulos devem ser escapados');
$assert((string)$evilXml->xpath('/s:svg/s:path')[0]->title === '<script>alert("x")</script> & \'aspas\': 3 de 4 (75,0%)',
    'texto escapado volta igual ao ler o XML');
$assert(str_contains((string)$evilXml['aria-label'], "outros\u{FFFD} 1 (25,0%)"), 'caractere proibido no XML vira U+FFFD');
$assert((string)$evilXml->xpath('/s:svg/s:path')[0]['fill'] === '#00897b', 'cor normalizada em minúsculas');

// Legenda: amostra, rótulo, valor e porcentagem.
$legend = $parse($chart->legend('legenda-teste'));
$items = $legend->xpath('/ul/li');
$itemText = static fn(SimpleXMLElement $item): string => dom_import_simplexml($item)->textContent;
$assert((string)$legend['id'] === 'legenda-teste' && count($items) === 2, 'legenda com um item por fatia');
$assert($itemText($items[0]) === 'Presenças 45 · 90,0%' && $itemText($items[1]) === 'Ausências 5 · 10,0%', 'texto da legenda');
$assert((string)$items[0]->svg['aria-hidden'] === 'true' && (string)$items[0]->svg->rect['fill'] === '#00897b',
    'amostra de cor decorativa na legenda');
$assert(!str_contains($chart->legend(), ' id='), 'legenda sem id quando não informado');
$evilLegend = $parse($evil->legend());
$assert(!str_contains($evil->legend(), '<script') && str_starts_with($itemText($evilLegend->li[0]), '<script>alert("x")</script>'),
    'legenda também escapa os rótulos');
$emptyLegend = $parse($empty->legend());
$assert($itemText($emptyLegend->li[0]) === 'Presenças 0' && !str_contains($empty->legend(), '%'),
    'sem total a legenda não mostra porcentagem');

// Chave de cores da grade: só amostra e rótulo, oculta dos leitores de tela como as pizzas pequenas.
$key = $parse($chart->key());
$keyItems = $key->xpath('/ul/li');
$assert((string)$key['aria-hidden'] === 'true' && count($keyItems) === 2, 'chave com um item por fatia, decorativa');
$assert($itemText($keyItems[0]) === 'Presenças' && $itemText($keyItems[1]) === 'Ausências'
    && (string)$keyItems[1]->svg->rect['fill'] === '#d67b3a', 'chave sem números, com a cor de cada fatia');
$assert(!str_contains($evil->key(), '<script') && str_starts_with($itemText($parse($evil->key())->li[0]), '<script>'),
    'chave também escapa os rótulos');

// Entradas inválidas: negativos, NaN, infinitos e o que não for número.
foreach ([-1, -0.5, NAN, INF, -INF, '5', null, true] as $badValue) {
    $rejects(static fn() => new PieChart([['label' => 'Presenças', 'value' => $badValue, 'color' => '#00897b']]),
        'valor inválido deve ser recusado: ' . var_export($badValue, true));
}
$rejects(static fn() => new PieChart([['label' => 'A', 'value' => 1]]), 'fatia sem cor recusada');
$rejects(static fn() => new PieChart([['label' => 'A', 'value' => PHP_FLOAT_MAX, 'color' => '#00897b'],
    ['label' => 'B', 'value' => PHP_FLOAT_MAX, 'color' => '#d67b3a']]), 'soma infinita recusada');
foreach (['red', '#fff', "#00897b\n", '#00897b" onload="x'] as $badColor) {
    $rejects(static fn() => new PieChart([['label' => 'A', 'value' => 1, 'color' => $badColor]]), 'cor inválida recusada');
}
$rejects(static fn() => new PieChart([]), 'lista vazia recusada');
$rejects(static fn() => new PieChart(['a' => ['label' => 'A', 'value' => 1, 'color' => '#00897b']]), 'fatias devem vir em lista');
$rejects(static fn() => new PieChart([['label' => '  ', 'value' => 1, 'color' => '#00897b']]), 'rótulo vazio recusado');
$rejects(static fn() => new PieChart(['fatia']), 'fatia precisa ser um array');
foreach ([0, 15, 1025] as $badSize) {
    $rejects(static fn() => $chart->svg($badSize), 'tamanho inválido recusado');
}

fwrite(STDOUT, "PieChartTest: {$assertions} assertions passed.\n");
