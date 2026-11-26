<?php
$tabs = [
    'turmas' => ['Turmas', '🏫', count($classes)],
    'alunos' => ['Alunos', '🎒', count($students)],
    'responsaveis' => ['Responsáveis', '👪', count($guardians)],
    'funcionarios' => ['Funcionários', '👤', null],
];
$activeTab = isset($tabs[$activeTab ?? '']) ? $activeTab : 'alunos';
// Depois de uma recusa do servidor, a aba do formulário enviado volta com o formulário aberto.
$hadError = (int)http_response_code() >= 400;
$classNames = [];
$studentsPerClass = [];
foreach ($classes as $class) {
    $classNames[(string)$class['_id']] = $class['name'];
}
$activeStudentIds = [];
foreach ($students as $student) {
    $activeStudentIds[(string)$student['_id']] = true;
    $key = (string)$student['class_id'];
    $studentsPerClass[$key] = ($studentsPerClass[$key] ?? 0) + 1;
}
$csrf = Auth::csrfToken();
?>
<nav class="tabs" role="tablist" aria-label="Tipos de cadastro" data-tabs>
    <?php foreach ($tabs as $key => [$label, $icon, $count]): ?>
        <a class="tab" role="tab" id="tab-<?= e($key) ?>" data-tab="<?= e($key) ?>" aria-controls="aba-<?= e($key) ?>"
           aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>" href="/cadastros?aba=<?= e($key) ?>"><span aria-hidden="true"><?= $icon ?></span> <?= e($label) ?><?php if ($count !== null): ?> <span class="count"><?= (int)$count ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<!-- Turmas -->
<section class="tab-panel" id="aba-turmas" data-panel="turmas" role="tabpanel" aria-labelledby="tab-turmas"<?= $activeTab === 'turmas' ? '' : ' hidden' ?>>
    <div class="panel-head">
        <h2>Turmas <span class="count" data-count-for="lista-turmas"><?= count($classes) ?></span></h2>
        <input class="search-input" type="search" placeholder="Buscar turma" aria-label="Buscar turma" data-filter="#lista-turmas" autocomplete="off">
    </div>
    <details class="new-item"<?= $hadError && $activeTab === 'turmas' ? ' open' : '' ?>>
        <summary class="button button-primary"><span aria-hidden="true">+</span> Nova turma</summary>
        <div class="new-item-body">
            <form method="post" action="/cadastros/turma" class="stack-form form-columns">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <label>Nome <input name="name" maxlength="120" required placeholder="Ex.: Turma 101"></label>
                <label>Série <input name="grade" maxlength="80" required placeholder="Ex.: 5º ano"></label>
                <label>Ano letivo <input name="academic_year" type="number" min="2000" max="2100" value="<?= e(date('Y')) ?>" required></label>
                <label>Turno <select name="shift" required><option>Manhã</option><option>Tarde</option><option>Noite</option><option>Integral</option></select></label>
                <button class="button button-primary">Cadastrar turma</button>
            </form>
        </div>
    </details>
    <?php if (!$classes): ?>
        <div class="card empty-state compact"><span class="empty-icon">🏫</span><strong>Nenhuma turma cadastrada</strong><span>Use “Nova turma” para cadastrar a primeira.</span></div>
    <?php else: ?>
        <div class="item-list" id="lista-turmas">
            <?php foreach ($classes as $class): ?>
                <details class="list-item" data-search="<?= e($class['name'] . ' ' . $class['grade'] . ' ' . $class['shift']) ?>">
                    <summary>
                        <span class="item-main"><strong><?= e($class['name']) ?> · <?= e($class['grade']) ?></strong>
                            <small><?= e($class['shift']) ?> · <?= e($class['academic_year']) ?> · <?= (int)($studentsPerClass[(string)$class['_id']] ?? 0) ?> aluno(s)</small></span>
                        <span class="item-action">Editar</span>
                    </summary>
                    <div class="list-item-body">
                        <form method="post" action="/cadastros/turma" class="stack-form form-columns">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
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
                        <form method="post" action="/cadastros/arquivar" class="archive-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= e((string)$class['_id']) ?>">
                            <input type="hidden" name="entity" value="turma">
                            <button class="button button-quiet button-small">Arquivar turma</button>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <p class="muted list-empty" data-empty-search hidden>Nenhuma turma encontrada para essa busca.</p>
    <?php endif; ?>
</section>

<!-- Alunos -->
<section class="tab-panel" id="aba-alunos" data-panel="alunos" role="tabpanel" aria-labelledby="tab-alunos"<?= $activeTab === 'alunos' ? '' : ' hidden' ?>>
    <div class="panel-head">
        <h2>Alunos <span class="count" data-count-for="lista-alunos"><?= count($students) ?></span></h2>
        <input class="search-input" type="search" placeholder="Buscar por nome, matrícula ou turma" aria-label="Buscar aluno" data-filter="#lista-alunos" autocomplete="off">
    </div>
    <details class="new-item"<?= $hadError && $activeTab === 'alunos' ? ' open' : '' ?>>
        <summary class="button button-primary"><span aria-hidden="true">+</span> Novo aluno</summary>
        <div class="new-item-body">
            <form method="post" action="/cadastros/aluno" class="stack-form form-columns">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <label>Nome <input name="name" maxlength="120" required></label>
                <label>Matrícula <input name="registration_no" maxlength="80"></label>
                <label>Data de nascimento <input name="birth_date" type="date" min="1900-01-01" max="<?= e(date('Y-m-d')) ?>"></label>
                <label>Turma <select name="class_id" required>
                    <option value="">Selecione</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= e((string)$class['_id']) ?>"><?= e($class['name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <button class="button button-primary">Cadastrar aluno</button>
            </form>
            <?php if (!$classes): ?><p class="form-hint">Cadastre uma turma antes de incluir alunos (aba Turmas).</p><?php endif; ?>
        </div>
    </details>
    <?php if (!$students): ?>
        <div class="card empty-state compact"><span class="empty-icon">🎒</span><strong>Nenhum aluno cadastrado</strong><span>Use “Novo aluno” para cadastrar o primeiro.</span></div>
    <?php else: ?>
        <div class="item-list" id="lista-alunos">
            <?php foreach ($students as $student): ?>
                <?php $className = $classNames[(string)$student['class_id']] ?? ''; ?>
                <details class="list-item" data-search="<?= e($student['name'] . ' ' . ($student['registration_no'] ?? '') . ' ' . $className) ?>">
                    <summary>
                        <span class="item-main"><strong><?= e($student['name']) ?><?= !empty($student['birth_date']) ? ' · nasc. ' . e(date('d/m/Y', strtotime($student['birth_date']))) : '' ?></strong>
                            <small><?= e($className) ?><?= !empty($student['registration_no']) ? ' · Matrícula ' . e($student['registration_no']) : '' ?></small></span>
                        <span class="item-action">Editar</span>
                    </summary>
                    <div class="list-item-body">
                        <form method="post" action="/cadastros/aluno" class="stack-form form-columns">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= e((string)$student['_id']) ?>">
                            <label>Nome <input name="name" maxlength="120" value="<?= e($student['name']) ?>" required></label>
                            <label>Matrícula <input name="registration_no" maxlength="80" value="<?= e($student['registration_no'] ?? '') ?>"></label>
                            <label>Data de nascimento <input name="birth_date" type="date" min="1900-01-01" max="<?= e(date('Y-m-d')) ?>" value="<?= e($student['birth_date'] ?? '') ?>"></label>
                            <label>Turma <select name="class_id" required>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= e((string)$class['_id']) ?>" <?= (string)$student['class_id'] === (string)$class['_id'] ? 'selected' : '' ?>><?= e($class['name']) ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <button class="button button-primary">Salvar aluno</button>
                        </form>
                        <form method="post" action="/cadastros/arquivar" class="archive-form">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= e((string)$student['_id']) ?>">
                            <input type="hidden" name="entity" value="aluno">
                            <button class="button button-quiet button-small">Arquivar aluno</button>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <p class="muted list-empty" data-empty-search hidden>Nenhum aluno encontrado para essa busca.</p>
    <?php endif; ?>
</section>

<!-- Responsáveis -->
<section class="tab-panel" id="aba-responsaveis" data-panel="responsaveis" role="tabpanel" aria-labelledby="tab-responsaveis"<?= $activeTab === 'responsaveis' ? '' : ' hidden' ?>>
    <div class="panel-head">
        <h2>Responsáveis <span class="count" data-count-for="lista-responsaveis"><?= count($guardians) ?></span></h2>
        <input class="search-input" type="search" placeholder="Buscar por nome ou e-mail" aria-label="Buscar responsável" data-filter="#lista-responsaveis" autocomplete="off">
    </div>
    <details class="new-item"<?= $hadError && $activeTab === 'responsaveis' ? ' open' : '' ?>>
        <summary class="button button-primary"><span aria-hidden="true">+</span> Novo responsável</summary>
        <div class="new-item-body">
            <form method="post" action="/cadastros/responsavel" class="stack-form form-columns">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                <label>Nome <input name="name" maxlength="120" required></label>
                <label>E-mail <input name="email" type="email" maxlength="254" required></label>
                <label>Telefone <input name="phone" type="tel" inputmode="tel" maxlength="25" placeholder="(11) 91234-5678" autocomplete="off"></label>
                <label>Parentesco <input name="relationship" maxlength="80" required placeholder="Ex.: Mãe, Pai, Avó"></label>
                <fieldset class="picker"><legend>Alunos vinculados</legend>
                    <?php if ($students): ?>
                        <input class="search-input" type="search" placeholder="Buscar aluno" aria-label="Buscar aluno para vincular" data-filter="#picker-novo" autocomplete="off">
                    <?php endif; ?>
                    <div class="picker-list" id="picker-novo">
                        <?php foreach ($students as $student): ?>
                            <label class="check-option" data-search="<?= e($student['name']) ?>"><input type="checkbox" name="student_ids[]" value="<?= e((string)$student['_id']) ?>"> <?= e($student['name']) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$students): ?><p class="muted">Nenhum aluno ativo para vincular.</p><?php endif; ?>
                </fieldset>
                <button class="button button-primary">Cadastrar responsável</button>
            </form>
        </div>
    </details>
    <?php if (!$guardians): ?>
        <div class="card empty-state compact"><span class="empty-icon">👪</span><strong>Nenhum responsável cadastrado</strong><span>Use “Novo responsável” para cadastrar o primeiro.</span></div>
    <?php else: ?>
        <div class="item-list" id="lista-responsaveis">
            <?php foreach ($guardians as $guardian): ?>
                <?php
                $guardianId = (string)$guardian['_id'];
                $devices = (int)(($pushDevices ?? [])[$guardianId] ?? 0);
                $linkedIds = $links[$guardianId] ?? [];
                $linkedActive = count(array_filter($linkedIds, static fn(string $id): bool => isset($activeStudentIds[$id])));
                ?>
                <details class="list-item" data-search="<?= e($guardian['name'] . ' ' . $guardian['email']) ?>">
                    <summary>
                        <span class="item-main"><strong><?= e($guardian['name']) ?> · <?= e($guardian['email']) ?><?= !empty($guardian['phone']) ? ' · ' . e(formatPhone($guardian['phone'])) : '' ?></strong>
                            <small><?= e($guardian['relationship']) ?> · <?= $linkedActive ?> aluno(s) · <span class="push-badge<?= $devices > 0 ? ' push-badge-on' : '' ?>"><?= $devices > 0 ? 'Push em ' . $devices . ' aparelho(s)' : 'Somente e-mail' ?></span></small></span>
                        <span class="item-action">Editar</span>
                    </summary>
                    <div class="list-item-body">
                        <form method="post" action="/cadastros/responsavel" class="stack-form form-columns">
                            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                            <input type="hidden" name="id" value="<?= e($guardianId) ?>">
                            <label>Nome <input name="name" maxlength="120" value="<?= e($guardian['name']) ?>" required></label>
                            <label>E-mail <input name="email" type="email" maxlength="254" value="<?= e($guardian['email']) ?>" required></label>
                            <label>Telefone <input name="phone" type="tel" inputmode="tel" maxlength="25" placeholder="(11) 91234-5678" autocomplete="off" value="<?= e(formatPhone($guardian['phone'] ?? null)) ?>"></label>
                            <label>Parentesco <input name="relationship" maxlength="80" value="<?= e($guardian['relationship']) ?>" required></label>
                            <fieldset class="picker"><legend>Alunos vinculados</legend>
                                <?php if ($students): ?>
                                    <input class="search-input" type="search" placeholder="Buscar aluno" aria-label="Buscar aluno para vincular" data-filter="#picker-<?= e($guardianId) ?>" autocomplete="off">
                                <?php endif; ?>
                                <div class="picker-list" id="picker-<?= e($guardianId) ?>">
                                    <?php foreach ($students as $student): ?>
                                        <label class="check-option" data-search="<?= e($student['name']) ?>"><input type="checkbox" name="student_ids[]" value="<?= e((string)$student['_id']) ?>" <?= in_array((string)$student['_id'], $linkedIds, true) ? 'checked' : '' ?>> <?= e($student['name']) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>
                            <button class="button button-primary">Salvar responsável</button>
                        </form>
                        <div class="item-extra-actions">
                            <?php if (!empty($pushEnabled)): ?>
                            <form method="post" action="/cadastros/responsavel/notificacoes">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= e($guardianId) ?>">
                                <button class="button button-outline button-small">Gerar convite de notificações no celular</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="/cadastros/arquivar" class="archive-form">
                                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
                                <input type="hidden" name="id" value="<?= e($guardianId) ?>">
                                <input type="hidden" name="entity" value="responsavel">
                                <button class="button button-quiet button-small">Arquivar responsável</button>
                            </form>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
        <p class="muted list-empty" data-empty-search hidden>Nenhum responsável encontrado para essa busca.</p>
    <?php endif; ?>
</section>

<!-- Funcionários -->
<section class="tab-panel" id="aba-funcionarios" data-panel="funcionarios" role="tabpanel" aria-labelledby="tab-funcionarios"<?= $activeTab === 'funcionarios' ? '' : ' hidden' ?>>
    <div class="panel-head">
        <h2>Novo funcionário</h2>
        <a class="button button-quiet" href="/funcionarios">Ver a lista de funcionários</a>
    </div>
    <div class="card">
        <p class="muted panel-intro">Crie o acesso de quem vai usar o sistema. A pessoa entra com o e-mail e a senha inicial informados abaixo; AOE e Coordenação podem trocar a senha por “Esqueci minha senha”.</p>
        <?php require __DIR__ . '/staff_form.php'; ?>
    </div>
</section>
