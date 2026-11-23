<?php $pageTitle = 'Chamada MongoDB - homologação'; ?>
<script src="/assets/attendance.js" defer></script>
<section class="page-heading">
    <div>
        <span class="eyebrow">Homologação MongoDB</span>
        <h1>Registrar chamada</h1>
        <p>Marque somente os alunos ausentes. Ao concluir, os demais serão registrados como presentes.</p>
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
        <input type="hidden" name="attendance_submitted" value="1">
        <div class="field date-field">
            <label for="absence_date">Data da chamada</label>
            <input id="absence_date" name="absence_date" type="date" required value="<?= e($absenceDate) ?>">
        </div>
        <fieldset class="student-picker">
            <legend>Chamada da turma <span class="legend-note">Marque apenas as ausências</span></legend>
            <?php if ($students === []): ?>
                <p class="muted">Não há alunos ativos nesta turma.</p>
            <?php elseif (count($students) > 1000): ?>
                <p class="notice notice-error">O limite de 1000 alunos por chamada foi excedido. Divida a turma antes de prosseguir.</p>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($students as $student): ?>
                        <?php $studentKey = (string)$student['_id']; ?>
                        <input type="hidden" name="roster_student_ids[]" value="<?= e($studentKey) ?>">
                        <label class="student-option attendance-option">
                            <span><strong><?= e($student['name']) ?></strong><small><?= e($student['registration_no'] ?? 'Sem matrícula informada') ?></small></span>
                            <span class="attendance-absence"><input type="checkbox" name="absent_student_ids[]" value="<?= e($studentKey) ?>"
                                <?= ($selectedAttendance[$studentKey] ?? null) === 'AUSENTE' ? 'checked' : '' ?>> Ausente</span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </fieldset>
        <?php if ($students !== [] && count($students) <= 1000): ?>
            <p class="notice attendance-summary" aria-live="polite" data-attendance-summary data-total="<?= count($students) ?>">
                Confira os <?= count($students) ?> alunos da turma antes de concluir. Os não marcados serão registrados como presentes.
            </p>
            <div class="form-actions"><button class="button button-primary" type="submit">Concluir chamada de homologação</button></div>
        <?php endif; ?>
    </form>
<?php else: ?>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="card empty-state"><strong>Selecione uma turma para começar</strong></section>
<?php endif; ?>
