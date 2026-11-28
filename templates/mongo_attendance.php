<?php
$pageTitle = 'Chamada';
$selectedClass = null;
foreach ($classes as $class) {
    if ((string)$class['_id'] === (string)$classId) {
        $selectedClass = $class;
        break;
    }
}
$studentCount = count($students);
$canRecord = $studentCount > 0 && $studentCount <= 1000;
$isAbsent = static fn(string $studentKey): bool => ($selectedAttendance[$studentKey] ?? null) === 'AUSENTE';
$markedCount = 0;
foreach ($students as $student) {
    $markedCount += $isAbsent((string)$student['_id']) ? 1 : 0;
}
$classDetails = array_filter([
    $selectedClass['grade'] ?? null,
    $selectedClass['shift'] ?? null,
    $canRecord ? ($studentCount === 1 ? '1 aluno' : $studentCount . ' alunos') : null,
], static fn($detail): bool => $detail !== null && $detail !== '');
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Chamada</span>
        <h1>Registrar chamada</h1>
        <p>Escolha a turma e marque só quem <strong>faltou</strong>. Quem não for marcado fica como <strong>presente</strong>.</p>
    </div>
</section>
<section class="card form-card class-selector-card">
    <form method="get" action="/chamada" class="class-selector-form chamada-turma-form" data-chamada-turma>
        <div class="field">
            <label for="class_id">Turma</label>
            <select id="class_id" name="turma_id" required aria-describedby="chamada-turma-dica">
                <option value="">Selecione uma turma</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e((string)$class['_id']) ?>" <?= (string)$class['_id'] === (string)$classId ? 'selected' : '' ?>>
                        <?= e($class['name']) ?> · <?= e($class['grade']) ?> · <?= e($class['shift']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="button button-quiet chamada-carregar" type="submit">Carregar alunos</button>
    </form>
    <?php if ($classes === []): ?>
        <p class="form-hint chamada-dica" id="chamada-turma-dica">Ainda não há turmas ativas. A coordenação cadastra as turmas em Cadastros.</p>
    <?php else: ?>
        <p class="form-hint chamada-dica" id="chamada-turma-dica" data-chamada-dica
           data-texto-js="A lista de alunos aparece assim que você escolhe a turma.">Escolha a turma e clique em “Carregar alunos” para ver a lista.</p>
    <?php endif; ?>
    <?php if (count($classes) >= 200): ?>
        <p class="form-hint chamada-dica">A lista mostra as 200 primeiras turmas, em ordem alfabética.</p>
    <?php endif; ?>
</section>
<?php if ($classId !== null): ?>
    <form class="card form-card attendance-picker-card chamada-form" method="post" action="/chamada" data-chamada-form>
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <input type="hidden" name="class_id" value="<?= e((string)$classId) ?>">
        <div class="chamada-topo">
            <div class="chamada-turma-atual">
                <span class="eyebrow">Turma</span>
                <h2><?= e($selectedClass['name'] ?? 'Turma selecionada') ?></h2>
                <?php if ($classDetails !== []): ?><p><?= e(implode(' · ', $classDetails)) ?></p><?php endif; ?>
            </div>
            <div class="field date-field">
                <label for="absence_date">Data da chamada</label>
                <input id="absence_date" name="absence_date" type="date" required value="<?= e($absenceDate) ?>">
            </div>
        </div>
        <fieldset class="chamada-alunos">
            <legend>Quem faltou?</legend>
            <?php if ($students === []): ?>
                <p class="muted">Não há alunos ativos nesta turma.</p>
            <?php elseif ($studentCount > 1000): ?>
                <p class="notice notice-error">O limite de 1000 alunos por chamada foi excedido. Divida a turma antes de prosseguir.</p>
            <?php else: ?>
                <p class="chamada-instrucao">Marque o cartão de cada aluno que faltou. Os demais ficam como presentes.</p>
                <?php /* Busca, contador e "Desmarcar todos" dependem do JS; sem ele, ficam ocultos. */ ?>
                <div class="chamada-ferramentas" data-chamada-ferramentas hidden>
                    <?php if ($studentCount >= 15): ?>
                        <div class="field chamada-busca">
                            <label for="chamada-busca">Procurar aluno</label>
                            <input id="chamada-busca" type="search" autocomplete="off" placeholder="Nome ou matrícula" data-chamada-busca>
                            <p class="chamada-busca-resultado" aria-live="polite" data-chamada-resultado></p>
                        </div>
                    <?php endif; ?>
                    <div class="chamada-barra">
                        <p class="chamada-contador" aria-live="polite" aria-atomic="true" data-chamada-contador><?= $markedCount === 0 ? 'Nenhuma falta marcada' : ($markedCount === 1 ? '1 falta marcada' : $markedCount . ' faltas marcadas') ?></p>
                        <div class="chamada-barra-acoes">
                            <button class="button button-quiet" type="button" data-chamada-desmarcar>Desmarcar todos</button>
                            <button class="button button-outline" type="button" data-chamada-desfazer hidden>Desfazer</button>
                        </div>
                    </div>
                </div>
                <ul class="chamada-lista" role="list">
                    <?php foreach ($students as $student): ?>
                        <?php
                        $studentKey = (string)$student['_id'];
                        $registration = trim((string)($student['registration_no'] ?? ''));
                        ?>
                        <li class="chamada-item" data-chamada-item>
                            <?php /* Quem não for marcado como ausente segue como presente: o campo oculto vem antes e a caixa, se marcada, vence (mesmo nome, vale o último valor). */ ?>
                            <input type="hidden" name="attendance[<?= e($studentKey) ?>]" value="PRESENTE">
                            <label class="chamada-aluno card-lift" for="falta-<?= e($studentKey) ?>">
                                <span class="chamada-aluno-dados" data-chamada-dados>
                                    <strong class="chamada-aluno-nome"><?= e($student['name']) ?></strong>
                                    <small><?= $registration === '' ? 'Sem matrícula informada' : 'Matrícula ' . e($registration) ?></small>
                                </span>
                                <span class="chamada-marca">
                                    <input class="chamada-caixa" type="checkbox" id="falta-<?= e($studentKey) ?>" name="attendance[<?= e($studentKey) ?>]" value="AUSENTE"
                                        data-chamada-caixa data-nome="<?= e($student['name']) ?>"<?= $isAbsent($studentKey) ? ' checked' : '' ?>>
                                    <span>Faltou</span>
                                </span>
                                <span class="chamada-selo" aria-hidden="true">Ausente</span>
                            </label>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </fieldset>
        <?php if ($canRecord): ?>
            <div class="chamada-envio">
                <button class="button button-primary chamada-enviar" type="submit" aria-describedby="chamada-envio-nota" data-chamada-enviar
                    data-texto-faltas="Concluir chamada e avisar responsáveis" data-texto-presentes="Concluir chamada (todos presentes)">Concluir chamada e avisar responsáveis</button>
                <p class="chamada-envio-nota" id="chamada-envio-nota">Os responsáveis dos alunos marcados recebem o aviso por e-mail ou por notificação no celular em poucos segundos.
                    Se marcar alguém por engano, dá para desfazer a falta depois, no resumo da chamada ou no detalhe da ocorrência.</p>
            </div>
        <?php endif; ?>
    </form>
    <?php if ($canRecord): ?>
        <?php /* Confirmações abertas pelo JS; sem JS a chamada é enviada direto. */ ?>
        <dialog class="chamada-dialogo" aria-labelledby="chamada-confirmacao-titulo" aria-describedby="chamada-confirmacao-corpo" data-chamada-confirmacao>
            <h2 id="chamada-confirmacao-titulo">Confirmar a chamada</h2>
            <div id="chamada-confirmacao-corpo">
                <p class="chamada-dialogo-contexto"><?= e($selectedClass['name'] ?? 'Turma selecionada') ?> · <span data-chamada-confirmacao-data></span></p>
                <p class="notice notice-warning" data-chamada-confirmacao-outro-dia hidden>Atenção: a data da chamada não é a de hoje.</p>
                <div data-chamada-confirmacao-faltas>
                    <p><strong data-chamada-confirmacao-total></strong>. Os responsáveis recebem o aviso assim que você confirmar:</p>
                    <ul class="chamada-dialogo-lista" tabindex="0" aria-label="Alunos marcados como ausentes" data-chamada-confirmacao-lista></ul>
                </div>
                <p data-chamada-confirmacao-presentes hidden>Nenhuma falta marcada: <?= $studentCount === 1 ? 'o aluno fica como presente' : 'todos os ' . $studentCount . ' alunos ficam como presentes' ?> e nenhum aviso é enviado.</p>
            </div>
            <div class="chamada-dialogo-acoes">
                <button class="button button-quiet" type="button" data-chamada-confirmacao-voltar>Voltar e revisar</button>
                <button class="button button-primary" type="button" data-chamada-confirmacao-enviar
                    data-texto-faltas="Confirmar e enviar avisos" data-texto-presentes="Confirmar chamada">Confirmar e enviar avisos</button>
            </div>
        </dialog>
        <dialog class="chamada-dialogo" aria-labelledby="chamada-troca-titulo" aria-describedby="chamada-troca-texto" data-chamada-troca>
            <h2 id="chamada-troca-titulo">Trocar de turma?</h2>
            <p id="chamada-troca-texto">As faltas marcadas nesta chamada ainda não foram enviadas. Se trocar de turma agora, elas serão descartadas.</p>
            <div class="chamada-dialogo-acoes">
                <button class="button button-quiet" type="button" data-chamada-troca-voltar>Continuar esta chamada</button>
                <button class="button button-primary" type="button" data-chamada-troca-confirmar>Trocar de turma</button>
            </div>
        </dialog>
    <?php endif; ?>
<?php else: ?>
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="card empty-state">
        <?php if ($classes === []): ?>
            <strong>Nenhuma turma ativa</strong>
            <span>Quando a coordenação cadastrar as turmas, elas aparecem no seletor acima.</span>
        <?php else: ?>
            <strong>Escolha uma turma para começar</strong>
            <span>A lista de alunos aparece aqui, com uma caixinha “Faltou” para marcar cada ausente.</span>
        <?php endif; ?>
    </section>
<?php endif; ?>
