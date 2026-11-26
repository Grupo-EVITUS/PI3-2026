<?php
$pageTitle = 'Chamada';
$crumbs = [['Chamada', null]];
$selectedClass = null;
foreach ($classes as $class) {
    if ($classId !== null && (string)$class['_id'] === (string)$classId) {
        $selectedClass = $class;
    }
}
$total = count($students);
$absentMarked = count(array_filter($selectedAttendance, static fn($status): bool => $status === 'AUSENTE'));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Rotina</span>
        <h1>Chamada</h1>
        <p>Todos os alunos começam como <strong>presentes</strong>. Marque apenas os <strong>ausentes</strong>.</p>
    </div>
</section>

<ol class="steps" aria-label="Etapas da chamada">
    <li class="step <?= $classId === null ? 'is-current' : 'is-done' ?>"><span class="step-number">1</span> Escolha a turma</li>
    <li class="step <?= $classId === null ? '' : 'is-current' ?>"><span class="step-number">2</span> Marque quem faltou</li>
    <li class="step"><span class="step-number">3</span> Conclua</li>
</ol>

<?php if ($error && $classId === null): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>

<section class="card class-picker" aria-labelledby="turmas-titulo">
    <h2 id="turmas-titulo">Turma</h2>
    <?php if (!$classes): ?>
        <p class="muted">Nenhuma turma ativa. Peça à Coordenação para cadastrar as turmas.</p>
    <?php else: ?>
        <div class="class-grid">
            <?php foreach ($classes as $class): ?>
                <a class="class-chip" href="/chamada?turma_id=<?= e((string)$class['_id']) ?>"<?= $selectedClass !== null && (string)$class['_id'] === (string)$selectedClass['_id'] ? ' aria-current="true"' : '' ?>>
                    <strong><?= e($class['name']) ?></strong>
                    <small><?= e($class['grade']) ?> · <?= e($class['shift']) ?></small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($classId !== null): ?>
    <form class="card form-card attendance-picker-card" method="post" action="/chamada" data-attendance>
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="class_id" value="<?= e((string)$classId) ?>">
        <div class="attendance-head">
            <div class="selected-class">
                <span class="eyebrow">Turma selecionada</span>
                <strong><?= $selectedClass !== null ? e($selectedClass['name']) : 'Turma' ?></strong>
                <small class="muted"><?= (int)$total ?> aluno(s) ativo(s)</small>
            </div>
            <div class="field date-field">
                <label for="absence_date">Data da chamada</label>
                <input id="absence_date" name="absence_date" type="date" required value="<?= e($absenceDate) ?>">
            </div>
            <?php if ($total > 8): ?>
                <input class="search-input" type="search" placeholder="Buscar aluno na lista" aria-label="Buscar aluno na lista" data-filter="#lista-chamada" autocomplete="off">
            <?php endif; ?>
        </div>
        <fieldset class="student-picker">
            <legend>Chamada da turma <span class="legend-note">Marque somente quem faltou</span></legend>
            <?php if ($students === []): ?>
                <p class="muted">Não há alunos ativos nesta turma.</p>
            <?php elseif ($total > 1000): ?>
                <p class="notice notice-error">O limite de 1000 alunos por chamada foi excedido. Divida a turma antes de prosseguir.</p>
            <?php else: ?>
                <div class="student-grid" id="lista-chamada">
                    <?php foreach ($students as $student): ?>
                        <?php $studentKey = (string)$student['_id']; ?>
                        <div class="student-option attendance-option" data-search="<?= e($student['name'] . ' ' . ($student['registration_no'] ?? '')) ?>">
                            <span class="student-name"><strong><?= e($student['name']) ?></strong><small><?= e($student['registration_no'] ?? 'Sem matrícula informada') ?></small></span>
                            <?php /* Quem não for marcado como ausente segue como presente: o campo oculto vem antes e a caixa, se marcada, vence (mesmo nome, vale o último valor). */ ?>
                            <input type="hidden" name="attendance[<?= e($studentKey) ?>]" value="PRESENTE">
                            <label><input type="checkbox" name="attendance[<?= e($studentKey) ?>]" aria-label="Ausente: <?= e($student['name']) ?>" value="AUSENTE"
                                <?= ($selectedAttendance[$studentKey] ?? null) === 'AUSENTE' ? 'checked' : '' ?>> Ausente</label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="muted list-empty" data-empty-search hidden>Nenhum aluno encontrado para essa busca.</p>
            <?php endif; ?>
        </fieldset>
        <?php if ($students !== [] && $total <= 1000): ?>
            <p class="form-hint">Quem não for marcado como ausente conta como presente e não gera aviso ao responsável. Depois de concluir, a chamada desta turma e data não pode ser refeita.</p>
            <p class="form-hint">A chamada coloca os avisos na fila. O envio depende da execução do worker SMTP, e a justificativa fica disponível depois da entrega.</p>
            <div class="attendance-bar">
                <div class="attendance-count">
                    <span><strong data-absent-count><?= (int)$absentMarked ?></strong> ausente(s)</span>
                    <span><strong data-present-count><?= (int)($total - $absentMarked) ?></strong> presente(s)</span>
                </div>
                <button class="button button-quiet button-small" type="button" data-clear-absent data-no-lock aria-label="Limpar as marcações de ausentes">Limpar</button>
                <button class="button button-primary" type="submit">Concluir chamada</button>
            </div>
        <?php endif; ?>
    </form>
<?php endif; ?>
