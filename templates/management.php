<?php
$pageTitle = 'Cadastros';
$editing = is_array($edit);
$classEdit = $editing && $editType === 'turma' ? $edit : [];
$studentEdit = $editing && $editType === 'aluno' ? $edit : [];
$guardianEdit = $editing && $editType === 'responsavel' ? $edit : [];
$guardianStudentIds = $guardianEdit['student_ids'] ?? [];
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Administração</span>
        <h1>Cadastros básicos</h1>
        <p>Gerencie turmas, alunos, responsáveis e contas da equipe escolar.</p>
    </div>
</section>

<div class="management-grid">
    <section class="card management-card">
        <div class="section-toolbar"><h2><?= $classEdit ? 'Editar turma' : 'Nova turma' ?></h2><?php if ($classEdit): ?><a class="text-link" href="/cadastros">Cancelar</a><?php endif; ?></div>
        <form method="post" action="/cadastros/turma" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="id" value="<?= e($classEdit['id'] ?? '') ?>">
            <div class="field"><label for="class-name">Nome da turma</label><input id="class-name" name="name" required maxlength="80" value="<?= e($classEdit['name'] ?? '') ?>" placeholder="Ex.: 5º ano A"></div>
            <div class="field"><label for="class-grade">Ano/série</label><input id="class-grade" name="grade" required maxlength="40" value="<?= e($classEdit['grade'] ?? '') ?>" placeholder="Ex.: 5º ano"></div>
            <div class="field"><label for="class-year">Ano letivo</label><input id="class-year" name="academic_year" type="number" min="2000" max="2100" required value="<?= e($classEdit['academic_year'] ?? date('Y')) ?>"></div>
            <div class="field"><label for="class-shift">Turno</label><select id="class-shift" name="shift" required>
                <?php foreach (['Manhã', 'Tarde', 'Noite', 'Integral'] as $shift): ?><option value="<?= e($shift) ?>" <?= ($classEdit['shift'] ?? '') === $shift ? 'selected' : '' ?>><?= e($shift) ?></option><?php endforeach; ?>
            </select></div>
            <button class="button button-primary" type="submit"><?= $classEdit ? 'Salvar turma' : 'Cadastrar turma' ?></button>
        </form>
        <div class="compact-list">
            <h3>Turmas ativas <span class="count"><?= count($classes) ?></span></h3>
            <?php foreach ($classes as $class): ?>
                <div class="compact-row">
                    <span><strong><?= e($class['name']) ?></strong><small><?= e($class['grade']) ?> · <?= (int)$class['academic_year'] ?> · <?= e($class['shift']) ?></small></span>
                    <span class="row-actions">
                        <a class="text-link" href="/cadastros?tipo=turma&id=<?= (int)$class['id'] ?>">Editar</a>
                        <form method="post" action="/cadastros/arquivar">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><input type="hidden" name="tipo" value="turma"><input type="hidden" name="id" value="<?= (int)$class['id'] ?>">
                            <button class="link-button" type="submit">Arquivar</button>
                        </form>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card management-card">
        <div class="section-toolbar"><h2><?= $studentEdit ? 'Editar aluno' : 'Novo aluno' ?></h2><?php if ($studentEdit): ?><a class="text-link" href="/cadastros">Cancelar</a><?php endif; ?></div>
        <form method="post" action="/cadastros/aluno" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="id" value="<?= e($studentEdit['id'] ?? '') ?>">
            <div class="field"><label for="student-name">Nome completo</label><input id="student-name" name="name" required maxlength="120" value="<?= e($studentEdit['name'] ?? '') ?>"></div>
            <div class="field"><label for="student-registration">Matrícula <span class="muted">(opcional)</span></label><input id="student-registration" name="registration_no" maxlength="40" value="<?= e($studentEdit['registration_no'] ?? '') ?>"></div>
            <div class="field"><label for="student-class">Turma</label><select id="student-class" name="class_id" required><option value="">Selecione</option>
                <?php foreach ($classes as $class): ?><option value="<?= (int)$class['id'] ?>" <?= (int)($studentEdit['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>><?= e($class['name']) ?> · <?= e($class['grade']) ?></option><?php endforeach; ?>
            </select></div>
            <button class="button button-primary" type="submit"><?= $studentEdit ? 'Salvar aluno' : 'Cadastrar aluno' ?></button>
        </form>
        <div class="compact-list">
            <h3>Alunos ativos <span class="count"><?= count($students) ?></span></h3>
            <?php foreach ($students as $student): ?>
                <div class="compact-row">
                    <span><strong><?= e($student['name']) ?></strong><small><?= e($student['class_name']) ?><?= $student['registration_no'] ? ' · ' . e($student['registration_no']) : '' ?></small></span>
                    <span class="row-actions">
                        <a class="text-link" href="/cadastros?tipo=aluno&id=<?= (int)$student['id'] ?>">Editar</a>
                        <form method="post" action="/cadastros/arquivar">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><input type="hidden" name="tipo" value="aluno"><input type="hidden" name="id" value="<?= (int)$student['id'] ?>">
                            <button class="link-button" type="submit">Arquivar</button>
                        </form>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card management-card">
        <div class="section-toolbar"><h2><?= $guardianEdit ? 'Editar responsável' : 'Novo responsável' ?></h2><?php if ($guardianEdit): ?><a class="text-link" href="/cadastros">Cancelar</a><?php endif; ?></div>
        <form method="post" action="/cadastros/responsavel" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <input type="hidden" name="id" value="<?= e($guardianEdit['id'] ?? '') ?>">
            <div class="field"><label for="guardian-name">Nome completo</label><input id="guardian-name" name="name" required maxlength="120" value="<?= e($guardianEdit['name'] ?? '') ?>"></div>
            <div class="field"><label for="guardian-email">E-mail</label><input id="guardian-email" name="email" type="email" required maxlength="254" value="<?= e($guardianEdit['email'] ?? '') ?>"></div>
            <div class="field"><label for="guardian-relationship">Parentesco</label><input id="guardian-relationship" name="relationship" required maxlength="40" value="<?= e($guardianEdit['relationship'] ?? '') ?>" placeholder="Ex.: Mãe"></div>
            <fieldset class="student-picker compact-picker">
                <legend>Alunos vinculados <span class="legend-note">Pode selecionar irmãos</span></legend>
                <?php foreach ($students as $student): ?>
                    <label class="student-option">
                        <input type="checkbox" name="student_ids[]" value="<?= (int)$student['id'] ?>" <?= in_array((int)$student['id'], $guardianStudentIds, true) ? 'checked' : '' ?>>
                        <span><strong><?= e($student['name']) ?></strong><small><?= e($student['class_name']) ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <button class="button button-primary" type="submit"><?= $guardianEdit ? 'Salvar responsável' : 'Cadastrar responsável' ?></button>
        </form>
        <div class="compact-list">
            <h3>Responsáveis ativos <span class="count"><?= count($guardians) ?></span></h3>
            <?php foreach ($guardians as $guardian): ?>
                <div class="compact-row">
                    <span><strong><?= e($guardian['name']) ?></strong><small><?= e($guardian['email']) ?> · <?= e($guardian['relationship']) ?><br>Alunos: <?= e($guardian['student_names'] ?: 'nenhum') ?></small></span>
                    <span class="row-actions">
                        <a class="text-link" href="/cadastros?tipo=responsavel&id=<?= (int)$guardian['id'] ?>">Editar</a>
                        <form method="post" action="/cadastros/arquivar">
                            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>"><input type="hidden" name="tipo" value="responsavel"><input type="hidden" name="id" value="<?= (int)$guardian['id'] ?>">
                            <button class="link-button" type="submit">Arquivar</button>
                        </form>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card management-card">
        <div class="section-toolbar"><h2>Usuários da equipe</h2></div>
        <form method="post" action="/cadastros/usuario" class="stack-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <div class="field"><label for="user-name">Nome</label><input id="user-name" name="name" required maxlength="120"></div>
            <div class="field"><label for="user-email">E-mail</label><input id="user-email" name="email" type="email" required maxlength="254"></div>
            <div class="field"><label for="user-profile">Perfil</label><select id="user-profile" name="profile" required><option value="AOE">AOE</option><option value="COORDENACAO">Coordenação</option><option value="DIRETOR">Diretor</option></select></div>
            <div class="field"><label for="user-password">Senha inicial</label><input id="user-password" name="password" type="password" minlength="10" required><small class="form-hint">Mínimo de 10 caracteres.</small></div>
            <button class="button button-primary" type="submit">Criar usuário</button>
        </form>
        <div class="compact-list">
            <h3>Usuários ativos <span class="count"><?= count($staff) ?></span></h3>
            <?php foreach ($staff as $user): ?>
                <div class="compact-row"><span><strong><?= e($user['name']) ?></strong><small><?= e($user['email']) ?> · <?= e($user['profile']) ?></small></span></div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
