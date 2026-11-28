<?php
$pageTitle = 'Detalhes da ausência';
$canManage = in_array($_SESSION['profile'] ?? '', ['AOE', 'COORDENACAO'], true);
$channelLabels = ['EMAIL' => 'E-mail', 'PUSH' => 'Notificação push'];
$absenceLabels = ['PENDENTE' => 'Pendente', 'RESPONDIDA' => 'Respondida', 'CANCELADA' => 'Falta desfeita'];
$noticeLabels = \Saa\Database\MongoOccurrences::NOTICE_LABELS;
$cancel = $absence['cancellation'];
$when = static fn(string $moment): string => date('d/m/Y', strtotime($moment)) . ' às ' . date('H:i', strtotime($moment));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Ocorrência</span>
        <h1><?= e($absence['student_name']) ?></h1>
        <p><?= e($absence['class_name']) ?><?= $absence['grade'] ? ' · ' . e($absence['grade']) : '' ?> · <?= $cancel ? 'falta desfeita de' : 'ausência em' ?> <?= e(date('d/m/Y', strtotime($absence['absence_date']))) ?></p>
    </div>
    <div class="detail-actions">
        <?php if ($canManage && $absence['attendance_session_id']): ?>
            <a class="button button-quiet" href="/chamada/<?= e($absence['attendance_session_id']) ?>">Ver chamada</a>
        <?php endif; ?>
        <?php if ($canManage && !$cancel): ?>
            <a class="button button-outline" href="/ausencias/<?= e($absence['id']) ?>/desfazer">Desfazer falta</a>
        <?php endif; ?>
        <a class="button button-quiet" href="/ocorrencias">Voltar à lista</a>
    </div>
</section>

<?php if ($cancel): ?>
    <div class="notice notice-warning undo-banner" role="status">
        <strong>Falta desfeita.</strong>
        Desfeita por <?= e($cancel['cancelled_by_name']) ?><?= $cancel['cancelled_at'] ? ' em ' . e($when($cancel['cancelled_at'])) : '' ?>.
        Motivo: <?= e($cancel['reason']) ?>
        <span class="undo-banner-note">A frequência conta presença neste dia. Avisos que ainda estavam na fila foram cancelados e quem já tinha recebido o aviso ganhou um e-mail de correção.</span>
    </div>
<?php endif; ?>

<div class="detail-grid">
    <section class="card card-lift">
        <div class="section-toolbar">
            <h2>Comunicações aos responsáveis</h2>
            <span class="status status-<?= e(strtolower($absence['status'])) ?>"><?= e($absenceLabels[$absence['status']] ?? $absence['status']) ?></span>
        </div>
        <?php if (!$absence['communications']): ?>
            <div class="notice notice-warning">Não havia responsável com e-mail cadastrado no momento do registro.</div>
        <?php else: ?>
            <div class="result-list">
                <?php foreach ($absence['communications'] as $communication): ?>
                    <article class="result-row">
                        <div>
                            <strong><?= e($communication['guardian_name']) ?></strong>
                            <small><?= e($communication['email_to']) ?> · <?= e($channelLabels[$communication['channel']] ?? $communication['channel']) ?><?= $communication['is_retry'] ? ' · reenvio' : '' ?><?= $communication['is_reminder'] ? ' · lembrete (push sem resposta)' : '' ?><?= $communication['is_correction'] ? ' · correção da falta desfeita' : '' ?></small>
                            <?php if ($communication['status'] === 'CANCELADA'): ?>
                                <small>Cancelado antes do envio: a falta foi desfeita.</small>
                            <?php elseif ($communication['failure_detail']): ?>
                                <small class="error-text"><?= e($communication['failure_detail']) ?></small>
                            <?php endif; ?>
                        </div>
                        <span class="status status-<?= e(strtolower($communication['status'])) ?>"><?= e($noticeLabels[$communication['status']] ?? $communication['status']) ?><?= $communication['retried'] ? ' · reenviada' : '' ?></span>
                        <?php if ($communication['status'] === 'FALHA' && !$communication['retried'] && $canManage && $absence['status'] === 'PENDENTE'): ?>
                            <form method="post" action="/comunicacoes/reenvio">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="communication_id" value="<?= e($communication['id']) ?>">
                                <button class="button button-small button-outline" type="submit">Reenviar</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <dl class="detail-meta">
            <div><dt>Registrada por</dt><dd><?= e($absence['registered_by_name']) ?></dd></div>
            <?php if ($absence['created_at']): ?>
                <div><dt>Registrada em</dt><dd><?= e(date('d/m/Y H:i', strtotime($absence['created_at']))) ?></dd></div>
            <?php endif; ?>
            <?php if ($cancel): ?>
                <div><dt>Desfeita por</dt><dd><?= e($cancel['cancelled_by_name']) ?></dd></div>
                <?php if ($cancel['cancelled_at']): ?>
                    <div><dt>Desfeita em</dt><dd><?= e(date('d/m/Y H:i', strtotime($cancel['cancelled_at']))) ?></dd></div>
                <?php endif; ?>
            <?php endif; ?>
        </dl>
    </section>

    <section class="card card-lift">
        <h2>Justificativa</h2>
        <?php if (!$absence['justification']): ?>
            <?php if ($cancel): ?>
                <div class="empty-state compact"><span class="empty-icon">✓</span><strong>Não é preciso justificativa</strong><span>A falta foi desfeita: o aluno estava presente.</span></div>
            <?php else: ?>
                <div class="empty-state compact"><span class="empty-icon">…</span><strong>Aguardando resposta</strong><span>O status muda quando o primeiro responsável responder.</span></div>
            <?php endif; ?>
        <?php else: $justification = $absence['justification']; ?>
            <div class="justification-card">
                <span class="status status-respondida">Respondida</span>
                <p class="reason-label"><?= e($reasons[$justification['reason']] ?? $justification['reason']) ?></p>
                <?php if ($justification['explanation']): ?><p><?= nl2br(e($justification['explanation'])) ?></p><?php endif; ?>
                <p class="muted">Enviada por <?= e($justification['guardian_name']) ?><?= $justification['submitted_at'] ? ' em ' . e(date('d/m/Y H:i', strtotime($justification['submitted_at']))) : '' ?></p>
                <?php if ($cancel): ?><p class="muted">Recebida antes de a falta ser desfeita; fica guardada no histórico.</p><?php endif; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
