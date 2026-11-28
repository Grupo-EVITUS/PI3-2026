<?php
$pageTitle = 'Desfazer falta';
$day = date('d/m/Y', strtotime($absence['absence_date']));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Desfazer falta</span>
        <h1>Desfazer a falta de <?= e($absence['student_name']) ?>?</h1>
        <p>Use quando o aluno foi marcado como ausente por engano.</p>
    </div>
    <a class="button button-quiet" href="<?= e($back) ?>">Voltar sem desfazer</a>
</section>

<section class="card form-card undo-card">
    <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <div class="absence-summary">
        <div><span>Aluno</span><strong><?= e($absence['student_name']) ?></strong></div>
        <div><span>Turma</span><strong><?= e($absence['class_name']) ?></strong></div>
        <div><span>Data da chamada</span><strong><?= e($day) ?></strong></div>
    </div>

    <h2 class="undo-title">O que vai acontecer</h2>
    <ul class="undo-effects">
        <li>A chamada passa a registrar <strong>presença</strong> de <?= e($absence['student_name']) ?> em <?= e($day) ?>, e a frequência é recalculada.</li>
        <li>
            <?php if ($preview['queued'] > 0): ?>
                <strong><?= (int)$preview['queued'] ?> aviso(s)</strong> ainda na fila serão cancelados e não chegarão aos responsáveis.
            <?php else: ?>
                Avisos que ainda estiverem na fila são cancelados e não chegam aos responsáveis.
            <?php endif; ?>
        </li>
        <li>
            <?php if ($preview['corrections'] > 0): ?>
                <strong><?= (int)$preview['corrections'] ?> responsável(is)</strong> que já receberam o aviso vão receber um e-mail de correção.
            <?php else: ?>
                Quem já tiver recebido o aviso recebe um e-mail de correção.
            <?php endif; ?>
        </li>
        <?php if ($absence['justification']): ?>
            <li>A justificativa já enviada pelo responsável continua guardada no histórico.</li>
        <?php endif; ?>
    </ul>

    <form method="post" action="/ausencias/<?= e($absence['id']) ?>/desfazer" class="undo-form">
        <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
        <div class="field">
            <label for="reason">Motivo <span class="muted">(opcional)</span></label>
            <textarea id="reason" name="reason" rows="3" maxlength="300" placeholder="Ex.: marquei o aluno errado na lista."><?= e($reason) ?></textarea>
            <small class="form-hint">Fica registrado no histórico. Se ficar em branco, registramos “Falta marcada por engano.”</small>
        </div>
        <div class="form-actions">
            <a class="button button-quiet" href="<?= e($back) ?>">Voltar sem desfazer</a>
            <button class="button button-primary" type="submit">Desfazer falta</button>
        </div>
    </form>
</section>
