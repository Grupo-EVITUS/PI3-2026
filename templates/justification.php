<?php
// Falta desfeita pela escola: o link continua abrindo, só para avisar que não há o que justificar.
$cancelled = $absence['absence_status'] === 'CANCELADA';
$pageTitle = $cancelled ? 'Aviso cancelado' : 'Justificar ausência';
?>
<section class="public-intro">
    <span class="eyebrow"><?= $cancelled ? 'Comunicado da escola' : 'Formulário do responsável' ?></span>
    <h1><?= $cancelled ? 'Aviso de ausência cancelado' : 'Justificativa de ausência' ?></h1>
    <p><?= $cancelled ? 'A escola corrigiu o registro da chamada deste dia.' : 'Confira os dados do comunicado e informe o motivo da ausência.' ?></p>
</section>
<section class="card public-card">
    <div class="absence-summary">
        <div><span>Aluno</span><strong><?= e($absence['student_name']) ?></strong></div>
        <div><span>Turma</span><strong><?= e($absence['class_name']) ?></strong></div>
        <div><span><?= $cancelled ? 'Data' : 'Data da ausência' ?></span><strong><?= e(date('d/m/Y', strtotime($absence['absence_date']))) ?></strong></div>
    </div>
    <?php if ($cancelled): ?>
        <div class="notice notice-success public-cancelled" role="status">
            <strong>Este aviso foi cancelado.</strong>
            A falta foi registrada por engano: <?= e($absence['student_name']) ?> estava presente.
            Não é preciso enviar justificativa. Pedimos desculpas pelo transtorno.
        </div>
    <?php elseif ($absence['absence_status'] === 'RESPONDIDA'): ?>
        <div class="notice notice-success">Esta ocorrência já foi respondida. O envio de outra justificativa não é permitido.</div>
        <div class="justification-card">
            <span class="status status-respondida">Resposta registrada</span>
            <p class="reason-label"><?= e($reasons[$absence['reason']] ?? $absence['reason']) ?></p>
            <?php if ($absence['explanation']): ?><p><?= nl2br(e($absence['explanation'])) ?></p><?php endif; ?>
            <p class="muted">Recebida em <?= e(date('d/m/Y H:i', strtotime($absence['submitted_at']))) ?></p>
        </div>
    <?php else: ?>
        <?php if ($error): ?><div class="notice notice-error" role="alert"><?= e($error) ?></div><?php endif; ?>
        <form method="post" class="public-form">
            <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
            <div class="field">
                <label for="reason">Motivo da ausência</label>
                <select id="reason" name="reason" required>
                    <option value="">Selecione uma opção</option>
                    <?php foreach ($reasons as $code => $label): ?>
                        <option value="<?= e($code) ?>" <?= $selectedReason === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="explanation">Explique, se necessário <span class="muted">(obrigatório para “Outro”)</span></label>
                <textarea id="explanation" name="explanation" rows="4" maxlength="1000" placeholder="Conte brevemente à escola o que aconteceu."><?= e($explanation) ?></textarea>
            </div>
            <button class="button button-primary button-full" type="submit">Enviar justificativa</button>
        </form>
    <?php endif; ?>
</section>
<?php if (isset($pushPanel)) { require __DIR__ . '/push_panel.php'; } ?>
