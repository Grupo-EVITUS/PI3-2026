<?php
$pageTitle = 'Frequência escolar';
$showRate = static fn(?float $rate): string => $rate === null ? 'Sem dados' : number_format($rate, 1, ',', '.') . '%';
$bandLabel = ['green' => 'Verde', 'yellow' => 'Amarelo', 'red' => 'Vermelho', 'no_data' => 'Sem dados'];
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
    <div class="card"><strong><?= (int)$report['summary']['session_count'] ?></strong><span>Chamadas concluídas</span></div>
    <div class="card"><strong><?= (int)$report['summary']['present'] ?></strong><span>Presenças</span></div>
    <div class="card"><strong><?= (int)$report['summary']['absent'] ?></strong><span>Ausências</span></div>
    <div class="card"><strong><?= e($showRate($report['summary']['rate'])) ?></strong><span>Frequência do filtro</span></div>
</section>
<section class="card frequency-section">
    <h2><?= $rawStudent === '' ? 'Frequência por turma' : 'Frequência do aluno por turma' ?></h2>
    <?php if ($rawStudent !== ''): ?><p class="muted">Com o filtro de aluno, cada turma mostra somente as chamadas em que esse aluno participou.</p><?php endif; ?>
    <?php if ($report['classes'] === []): ?><p class="muted">Sem dados para os filtros selecionados.</p><?php endif; ?>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Turma</th><th scope="col">Presenças</th><th scope="col">Ausências</th><th scope="col">Taxa</th><th scope="col">Gráfico</th></tr></thead>
        <tbody>
        <?php foreach ($report['classes'] as $row): ?>
            <tr><td><strong><?= e($row['name']) ?></strong></td><td><?= (int)$row['present'] ?></td><td><?= (int)$row['absent'] ?></td>
                <td><span class="frequency-band band-<?= e($row['band']) ?>"><?= e($showRate($row['rate'])) ?> · <?= e($bandLabel[$row['band']] ?? 'Sem dados') ?></span></td>
                <td><progress class="frequency-progress band-<?= e($row['band']) ?>" max="100" value="<?= e((string)($row['rate'] ?? 0)) ?>" aria-label="Frequência da turma <?= e($row['name']) ?>: <?= e($showRate($row['rate'])) ?>"></progress></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<section class="card frequency-section">
    <h2>Frequência por aluno</h2>
    <?php if ($report['students'] === []): ?><p class="muted">Sem dados para os filtros selecionados.</p><?php endif; ?>
    <div class="table-wrap"><table>
        <thead><tr><th scope="col">Aluno</th><th scope="col">Presenças</th><th scope="col">Ausências</th><th scope="col">Taxa</th><th scope="col">Gráfico</th></tr></thead>
        <tbody>
        <?php foreach ($report['students'] as $row): ?>
            <tr><td><strong><?= e($row['name']) ?></strong></td><td><?= (int)$row['present'] ?></td><td><?= (int)$row['absent'] ?></td>
                <td><span class="frequency-band band-<?= e($row['band']) ?>"><?= e($showRate($row['rate'])) ?> · <?= e($bandLabel[$row['band']] ?? 'Sem dados') ?></span></td>
                <td><progress class="frequency-progress band-<?= e($row['band']) ?>" max="100" value="<?= e((string)($row['rate'] ?? 0)) ?>" aria-label="Frequência de <?= e($row['name']) ?>: <?= e($showRate($row['rate'])) ?>"></progress></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<p class="list-note">Verde: 90% ou mais. Amarelo: de 85% a menos de 90%. Vermelho: abaixo de 85%. Ausência justificada continua sendo ausência. Dados legados sem chamada completa ficam fora do cálculo.</p>
