<?php $pageTitle = 'Registrar ausência'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Registro diário</span>
        <h1>Registrar ausência</h1>
        <p>Selecione a turma e todos os alunos ausentes nesta data.</p>
    </div>
    <a class="button button-quiet" href="/ocorrencias">Voltar às ocorrências</a>
</section>

<section class="card form-card class-selector-card">
    <form method="get" action="/ausencias/registrar" class="class-selector-form">
        <div class="field">
            <label for="class_id">Turma</label>
            <select id="class_id" name="turma_id" required>
                <option value="">Selecione uma turma</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>" <?= $selectedClass === (int)$class['id'] ? 'selected' : '' ?>>
                        <?= e($class['name']) ?> · <?= e($class['grade']) ?> · <?= e($class['shift']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="button button-quiet" type="submit">Carregar alunos</button>
    </form>
</section>

<?php if ($selectedClass > 0): ?>
    <form class="card form-card attendance-picker-card" method="post" action="/ausencias/registrar">
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="class_id" value="<?= (int)$selectedClass ?>">
        <div class="selected-class">
            <span class="eyebrow">Turma selecionada</span>
            <strong>
                <?php foreach ($classes as $class): ?>
                    <?php if ((int)$class['id'] === $selectedClass): ?><?= e($class['name']) ?> · <?= e($class['grade']) ?> · <?= e($class['shift']) ?><?php endif; ?>
                <?php endforeach; ?>
            </strong>
        </div>
        <div class="field date-field">
            <label for="absence_date">Data da ausência</label>
            <input id="absence_date" name="absence_date" type="date" required value="<?= e($absenceDate) ?>">
        </div>
        <fieldset class="student-picker">
            <legend>Alunos <span class="legend-note">Marque um ou mais nomes</span></legend>
            <?php if (!$students): ?>
                <p class="muted">Não há alunos ativos nesta turma.</p>
            <?php else: ?>
                <div class="student-grid">
                    <?php foreach ($students as $student): ?>
                        <label class="student-option">
                            <input type="checkbox" name="student_ids[]" value="<?= (int)$student['id'] ?>"
                                <?= in_array((int)$student['id'], $selectedStudents, true) ? 'checked' : '' ?>>
                            <span><strong><?= e($student['name']) ?></strong><small><?= $student['registration_no'] ? e($student['registration_no']) : 'Sem matrícula informada' ?></small></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint">Cada aluno pode ter uma ausência por dia.</p>
            <?php endif; ?>
        </fieldset>
        <div class="form-actions">
            <button class="button button-primary" type="submit">Registrar e colocar e-mails na fila</button>
        </div>
    </form>
<?php else: ?>
    <section class="card empty-state">
        <span class="empty-icon">1</span>
        <strong>Selecione uma turma para começar</strong>
        <span>Os alunos dessa turma serão carregados para a chamada.</span>
    </section>
<?php endif; ?>
