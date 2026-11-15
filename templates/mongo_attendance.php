<?php $pageTitle = 'Chamada MongoDB - homologação'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Homologação MongoDB</span>
        <h1>Registrar chamada</h1>
        <p>Marque todos os alunos ativos como presentes ou ausentes.</p>
    </div>
</section>
<div class="notice notice-warning" role="status">
    <strong>Homologação MongoDB.</strong>
    A chamada coloca as comunicações na fila. O envio depende da execução do worker SMTP MongoDB;
    a justificativa fica disponível depois da entrega. Use esta tela somente para testes de homologação.
</div>
<section class="card form-card class-selector-card">
    <form method="get" action="/chamada" class="class-selector-form">
        <div class="field">
            <label for="class_id">Turma</label>
            <select id="class_id" name="turma_id" required>
                <option value="">Selecione uma turma</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e((string)$class['_id']) ?>" <?= (string)$class['_id'] === (string)$classId ? 'selected' : '' ?>>
                        <?= e($class['name']) ?> · <?= e($class['grade']) ?> · <?= e($class['shift']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="button button-quiet" type="submit">Carregar alunos</button>
    </form>
    <p class="form-hint">A lista de seleção mostra as primeiras 200 turmas. A paginação está planejada.</p>
</section>
<?php if ($classId !== null): ?>
    <form class="card form-card attendance-picker-card" method="post" action="/chamada">
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="class_id" value="<?= e((string)$classId) ?>">
        <div class="field date-field">
            <label for="absence_date">Data da chamada</label>
            <input id="absence_date" name="absence_date" type="date" required value="<?= e($absenceDate) ?>">
        </div>
        <fieldset class="student-picker">
            <legend>Chamada da turma <span class="legend-note">Informe a situação de cada aluno</span></legend>
            <?php if ($students === []): ?>
                <p class="muted">Não há alunos ativos nesta turma.</p>
            <?php elseif (count($students) > 1000): ?>
                <p class="notice notice-error">O limite de 1000 alunos por chamada foi excedido. Divida a turma antes de prosseguir.</p>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($students as $student): ?>
                        <?php $studentKey = (string)$student['_id']; ?>
                        <fieldset class="student-option attendance-option">
                            <legend><strong><?= e($student['name']) ?></strong><small><?= e($student['registration_no'] ?? 'Sem matrícula informada') ?></small></legend>
                            <label><input type="radio" name="attendance[<?= e($studentKey) ?>]" value="PRESENTE" required
                                <?= ($selectedAttendance[$studentKey] ?? null) === 'PRESENTE' ? 'checked' : '' ?>> Presente</label>
                            <label><input type="radio" name="attendance[<?= e($studentKey) ?>]" value="AUSENTE" required
                                <?= ($selectedAttendance[$studentKey] ?? null) === 'AUSENTE' ? 'checked' : '' ?>> Ausente</label>
                        </fieldset>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <?php if ($students !== [] && count($students) <= 1000): ?>
            <div class="form-actions"><button class="button button-primary" type="submit">Concluir chamada de homologação</button></div>
        <?php endif; ?>
    </form>
<?php else: ?>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="card empty-state"><strong>Selecione uma turma para começar</strong></section>
<?php endif; ?>
