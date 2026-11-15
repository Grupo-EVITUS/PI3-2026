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
    <ul><?php foreach ($classes as $class): ?><li><?= e($class['name']) ?> · <?= e($class['grade']) ?></li><?php endforeach; ?></ul>
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
    <ul><?php foreach ($students as $student): ?><li><?= e($student['name']) ?></li><?php endforeach; ?></ul>
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
    <ul><?php foreach ($guardians as $guardian): ?><li><?= e($guardian['name']) ?> · <?= e($guardian['email']) ?></li><?php endforeach; ?></ul>
</section>
