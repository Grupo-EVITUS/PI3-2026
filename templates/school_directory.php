<section class="card">
    <h2>Turmas</h2>
    <form method="post" action="/cadastros/turma" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <label>Nome <input name="name" maxlength="120" required></label>
        <label>Série <input name="grade" maxlength="80" required></label>
        <label>Ano letivo <input name="academic_year" type="number" min="2000" max="2100" value="<?= e(date('Y')) ?>" required></label>
        <label>Turno <select name="shift" required><option>Manhã</option><option>Tarde</option><option>Noite</option><option>Integral</option></select></label>
        <button class="button button-primary">Cadastrar turma</button>
    </form>
    <?php foreach ($classes as $class): ?>
        <details><summary><?= e($class['name']) ?> · <?= e($class['grade']) ?></summary>
            <form method="post" action="/cadastros/turma" class="stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$class['_id']) ?>">
                <label>Nome <input name="name" maxlength="120" value="<?= e($class['name']) ?>" required></label>
                <label>Série <input name="grade" maxlength="80" value="<?= e($class['grade']) ?>" required></label>
                <label>Ano letivo <input name="academic_year" type="number" min="2000" max="2100" value="<?= e($class['academic_year']) ?>" required></label>
                <label>Turno <select name="shift" required>
                    <?php foreach (['Manhã', 'Tarde', 'Noite', 'Integral'] as $shift): ?>
                        <option value="<?= e($shift) ?>" <?= $class['shift'] === $shift ? 'selected' : '' ?>><?= e($shift) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="button button-primary">Salvar turma</button>
            </form>
            <form method="post" action="/cadastros/arquivar">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$class['_id']) ?>">
                <input type="hidden" name="entity" value="turma">
                <button class="button button-quiet">Arquivar turma</button>
            </form>
        </details>
    <?php endforeach; ?>
</section>
<section class="card">
    <h2>Alunos</h2>
    <form method="post" action="/cadastros/aluno" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <label>Nome <input name="name" maxlength="120" required></label>
        <label>Matrícula <input name="registration_no" maxlength="80"></label>
        <label>Turma <select name="class_id" required>
            <option value="">Selecione</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= e((string)$class['_id']) ?>"><?= e($class['name']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <button class="button button-primary">Cadastrar aluno</button>
    </form>
    <?php foreach ($students as $student): ?>
        <details><summary><?= e($student['name']) ?></summary>
            <form method="post" action="/cadastros/aluno" class="stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$student['_id']) ?>">
                <label>Nome <input name="name" maxlength="120" value="<?= e($student['name']) ?>" required></label>
                <label>Matrícula <input name="registration_no" maxlength="80" value="<?= e($student['registration_no'] ?? '') ?>"></label>
                <label>Turma <select name="class_id" required>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= e((string)$class['_id']) ?>" <?= (string)$student['class_id'] === (string)$class['_id'] ? 'selected' : '' ?>><?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="button button-primary">Salvar aluno</button>
            </form>
            <form method="post" action="/cadastros/arquivar">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$student['_id']) ?>">
                <input type="hidden" name="entity" value="aluno">
                <button class="button button-quiet">Arquivar aluno</button>
            </form>
        </details>
    <?php endforeach; ?>
</section>
<section class="card">
    <h2>Responsáveis</h2>
    <form method="post" action="/cadastros/responsavel" class="stack-form">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <label>Nome <input name="name" maxlength="120" required></label>
        <label>E-mail <input name="email" type="email" maxlength="254" required></label>
        <label>Parentesco <input name="relationship" maxlength="80" required></label>
        <fieldset><legend>Alunos vinculados</legend>
            <?php foreach ($students as $student): ?>
                <label><input type="checkbox" name="student_ids[]" value="<?= e((string)$student['_id']) ?>"> <?= e($student['name']) ?></label>
            <?php endforeach; ?>
        </fieldset>
        <button class="button button-primary">Cadastrar responsável</button>
    </form>
    <?php foreach ($guardians as $guardian): ?>
        <details><summary><?= e($guardian['name']) ?> · <?= e($guardian['email']) ?></summary>
            <form method="post" action="/cadastros/responsavel" class="stack-form">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$guardian['_id']) ?>">
                <label>Nome <input name="name" maxlength="120" value="<?= e($guardian['name']) ?>" required></label>
                <label>E-mail <input name="email" type="email" maxlength="254" value="<?= e($guardian['email']) ?>" required></label>
                <label>Parentesco <input name="relationship" maxlength="80" value="<?= e($guardian['relationship']) ?>" required></label>
                <fieldset><legend>Alunos vinculados</legend>
                    <?php foreach ($students as $student): ?>
                        <label><input type="checkbox" name="student_ids[]" value="<?= e((string)$student['_id']) ?>" <?= in_array((string)$student['_id'], $links[(string)$guardian['_id']] ?? [], true) ? 'checked' : '' ?>> <?= e($student['name']) ?></label>
                    <?php endforeach; ?>
                </fieldset>
                <button class="button button-primary">Salvar responsável</button>
            </form>
            <form method="post" action="/cadastros/arquivar">
                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= e((string)$guardian['_id']) ?>">
                <input type="hidden" name="entity" value="responsavel">
                <button class="button button-quiet">Arquivar responsável</button>
            </form>
        </details>
    <?php endforeach; ?>
</section>
