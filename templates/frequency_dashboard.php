<?php
use Saa\View\PieChart;

$pageTitle = 'Frequência escolar';
$showRate = static fn(?float $rate): string => $rate === null ? 'Sem dados' : number_format($rate, 1, ',', '.') . '%';
$bandLabel = ['green' => 'Verde', 'yellow' => 'Amarelo', 'red' => 'Vermelho', 'no_data' => 'Sem dados'];
// Cores validadas sobre fundo branco (contraste e daltonismo); rótulos e números ficam nas cores de texto.
$pieChart = static fn(int $present, int $absent): PieChart => new PieChart([
    ['label' => 'Presenças', 'value' => $present, 'color' => '#00897b'],
    ['label' => 'Ausências', 'value' => $absent, 'color' => '#d67b3a'],
]);
// Mesmos filtros da página trocando só a turma: é o destino do cartão quando o JS não roda.
$classUrl = static fn(string $classId): string => '/frequencia?' . http_build_query(array_filter(
    ['periodo' => $period, 'referencia' => $reference, 'turma_id' => $classId, 'aluno_id' => $rawStudent],
    static fn(string $value): bool => $value !== ''
), '', '&', PHP_QUERY_RFC3986);
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Homologação MongoDB</span>
        <h1>Frequência escolar</h1>
        <p>Somente chamadas completas e concluídas entram no cálculo.</p>
    </div>
</section>
<form class="card frequency-filters" method="get" action="/frequencia">
    <label>Período
        <select name="periodo">
            <option value="semana" <?= $period === 'semana' ? 'selected' : '' ?>>Semana (segunda a domingo)</option>
            <option value="mes" <?= $period === 'mes' ? 'selected' : '' ?>>Mês</option>
            <option value="semestre" <?= $period === 'semestre' ? 'selected' : '' ?>>Semestre civil</option>
        </select>
    </label>
    <label>Data de referência <input type="date" name="referencia" value="<?= e($reference) ?>" required></label>
    <label>Turma
        <select name="turma_id">
            <option value="">Todas</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= e((string)$class['_id']) ?>" <?= $rawClass === (string)$class['_id'] ? 'selected' : '' ?>><?= e($class['name']) ?> · <?= e($class['academic_year']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Aluno
        <select name="aluno_id">
            <option value="">Todos</option>
            <?php foreach ($students as $student): ?>
                <option value="<?= e((string)$student['_id']) ?>" <?= $rawStudent === (string)$student['_id'] ? 'selected' : '' ?>><?= e($student['name']) ?><?= isset($student['registration_no']) ? ' · ' . e($student['registration_no']) : '' ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <button class="button button-primary" type="submit">Aplicar filtros</button>
</form>
<p class="list-note">De <?= e($first->format('d/m/Y')) ?> a <?= e($last->format('d/m/Y')) ?>. Turmas e alunos arquivados permanecem disponíveis para consulta histórica.</p>
<section class="frequency-summary" aria-label="Resumo da frequência">
    <div class="card card-lift"><strong><?= (int)$report['summary']['session_count'] ?></strong><span>Chamadas concluídas</span></div>
    <div class="card card-lift"><strong><?= (int)$report['summary']['present'] ?></strong><span>Presenças</span></div>
    <div class="card card-lift"><strong><?= (int)$report['summary']['absent'] ?></strong><span>Ausências</span></div>
    <div class="card card-lift"><strong><?= e($showRate($report['summary']['rate'])) ?></strong><span>Frequência do filtro</span></div>
</section>
<section class="class-pies" aria-labelledby="class-pies-title">
    <div class="class-pies-heading">
        <div>
            <h2 id="class-pies-title"><?= $rawStudent === '' ? 'Presença por turma' : 'Presença do aluno por turma' ?></h2>
            <p class="muted" data-pie-hint hidden>Clique ou toque em uma turma para ver o gráfico ampliado.</p>
        </div>
        <?php if ($report['classes'] !== []): ?><?= $pieChart(0, 0)->key() ?><?php endif; ?>
    </div>
    <?php if ($report['classes'] === []): ?>
        <p class="muted">Sem dados para os filtros selecionados.</p>
    <?php else: ?>
        <ul class="class-pie-grid">
            <?php foreach ($report['classes'] as $row):
                $chart = $pieChart((int)$row['present'], (int)$row['absent']);
                $dialogId = 'pizza-turma-' . $row['class_id'];
                $classLink = $classUrl((string)$row['class_id']);
                $band = $bandLabel[$row['band']] ?? 'Sem dados'; ?>
                <li>
                    <a class="card card-lift class-pie-card" href="<?= e($classLink) ?>" data-pie-dialog="<?= e($dialogId) ?>">
                        <span class="class-pie-name"><?= e($row['name']) ?></span>
                        <span class="class-pie-rate"><?= e($showRate($row['rate'])) ?></span>
                        <span class="frequency-band band-<?= e($row['band']) ?>"><?= e($band) ?></span>
                        <?= $chart->svg(72, decorative: true) ?>
                    </a>
                    <dialog class="pie-dialog" id="<?= e($dialogId) ?>" aria-labelledby="<?= e($dialogId) ?>-titulo" aria-describedby="<?= e($dialogId) ?>-legenda">
                        <div class="pie-dialog-body">
                            <span class="eyebrow"><?= $rawStudent === '' ? 'Presença da turma' : 'Presença do aluno na turma' ?></span>
                            <h2 id="<?= e($dialogId) ?>-titulo" tabindex="-1"><?= e($row['name']) ?></h2>
                            <p class="muted pie-dialog-period">De <?= e($first->format('d/m/Y')) ?> a <?= e($last->format('d/m/Y')) ?></p>
                            <div class="pie-dialog-chart">
                                <?= $chart->svg(240) ?>
                                <?= $chart->legend($dialogId . '-legenda') ?>
                            </div>
                            <dl class="pie-dialog-facts">
                                <div><dt>Total de registros</dt><dd><?= e(number_format((int)$row['total'], 0, ',', '.')) ?></dd></div>
                                <div><dt>Chamadas concluídas</dt><dd><?= e(number_format((int)$row['session_count'], 0, ',', '.')) ?></dd></div>
                                <div><dt>Faixa</dt><dd><span class="frequency-band band-<?= e($row['band']) ?>"><?= e($band) ?></span></dd></div>
                            </dl>
                            <div class="pie-dialog-actions">
                                <a class="button button-primary" href="<?= e($classLink) ?>#frequencia-alunos"><?= $rawStudent === '' ? 'Ver alunos desta turma' : 'Ver o aluno nesta turma' ?></a>
                                <button class="button button-quiet" type="button" data-pie-close>Fechar</button>
                            </div>
                        </div>
                    </dialog>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<section class="card frequency-section">
    <h2><?= $rawStudent === '' ? 'Frequência por turma' : 'Frequência do aluno por turma' ?></h2>
    <?php if ($rawStudent !== ''): ?><p class="muted">Com o filtro de aluno, cada turma mostra somente as chamadas em que esse aluno participou.</p><?php endif; ?>
    <?php if ($report['classes'] === []): ?><p class="muted">Sem dados para os filtros selecionados.</p><?php endif; ?>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Turma</th><th scope="col">Presenças</th><th scope="col">Ausências</th><th scope="col">Taxa</th><th scope="col">Gráfico</th></tr></thead>
        <tbody>
        <?php foreach ($report['classes'] as $row): ?>
            <tr><td data-label="Turma"><strong><?= e($row['name']) ?></strong></td><td data-label="Presenças"><?= (int)$row['present'] ?></td><td data-label="Ausências"><?= (int)$row['absent'] ?></td>
                <td data-label="Taxa"><span class="frequency-band band-<?= e($row['band']) ?>"><?= e($showRate($row['rate'])) ?> · <?= e($bandLabel[$row['band']] ?? 'Sem dados') ?></span></td>
                <td data-label="Gráfico"><progress class="frequency-progress band-<?= e($row['band']) ?>" max="100" value="<?= e((string)($row['rate'] ?? 0)) ?>" aria-label="<?= $rawStudent === '' ? 'Frequência da turma' : 'Frequência do aluno na turma' ?> <?= e($row['name']) ?>: <?= e($showRate($row['rate'])) ?>"></progress></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<section class="card frequency-section" id="frequencia-alunos">
    <h2>Frequência por aluno</h2>
    <?php if ($report['students'] === []): ?><p class="muted">Sem dados para os filtros selecionados.</p><?php endif; ?>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Aluno</th><th scope="col">Presenças</th><th scope="col">Ausências</th><th scope="col">Taxa</th><th scope="col">Gráfico</th></tr></thead>
        <tbody>
        <?php foreach ($report['students'] as $row): ?>
            <tr><td data-label="Aluno"><strong><?= e($row['name']) ?></strong></td><td data-label="Presenças"><?= (int)$row['present'] ?></td><td data-label="Ausências"><?= (int)$row['absent'] ?></td>
                <td data-label="Taxa"><span class="frequency-band band-<?= e($row['band']) ?>"><?= e($showRate($row['rate'])) ?> · <?= e($bandLabel[$row['band']] ?? 'Sem dados') ?></span></td>
                <td data-label="Gráfico"><progress class="frequency-progress band-<?= e($row['band']) ?>" max="100" value="<?= e((string)($row['rate'] ?? 0)) ?>" aria-label="Frequência de <?= e($row['name']) ?>: <?= e($showRate($row['rate'])) ?>"></progress></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<p class="list-note">Verde: 90% ou mais. Amarelo: de 85% a menos de 90%. Vermelho: abaixo de 85%. Ausência justificada continua sendo ausência. Dados legados sem chamada completa ficam fora do cálculo.</p>
