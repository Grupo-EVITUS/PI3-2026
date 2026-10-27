<?php $pageTitle = 'Justificar ausência'; ?>
<section class="public-intro">
    <span class="eyebrow">Formulário do responsável</span>
    <h1>Justificativa de ausência</h1>
    <p>Confira os dados do comunicado e informe o motivo da ausência.</p>
</section>
<section class="card public-card">
    <div class="absence-summary">
        <div><span>Aluno</span><strong><?= e($absence['student_name']) ?></strong></div>
        <div><span>Turma</span><strong><?= e($absence['class_name']) ?></strong></div>
        <div><span>Data da ausência</span><strong><?= e(date('d/m/Y', strtotime($absence['absence_date']))) ?></strong></div>
    </div>
    <?php if ($absence['absence_status'] === 'RESPONDIDA'): ?>
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
