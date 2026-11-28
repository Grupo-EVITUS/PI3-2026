<?php
$pageTitle = 'Resumo da chamada';
$absenceLabels = ['PENDENTE' => 'Pendente', 'RESPONDIDA' => 'Respondida', 'CANCELADA' => 'Falta desfeita'];
$noticeLabels = \Saa\Database\MongoOccurrences::NOTICE_LABELS;
$channelLabels = ['EMAIL' => 'E-mail', 'PUSH' => 'Notificação push'];
$when = static fn(string $moment): string => date('d/m/Y', strtotime($moment)) . ' às ' . date('H:i', strtotime($moment));
$counts = $summary['counts'];
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Resumo da chamada</span>
        <h1><?= e($summary['class_name']) ?></h1>
        <p><?= e(date('d/m/Y', strtotime($summary['attendance_date']))) ?><?= $summary['class_shift'] ? ' · ' . e($summary['class_shift']) : '' ?>
            · feita por <?= e($summary['completed_by_name']) ?><?= $summary['completed_at'] ? ' em ' . e($when($summary['completed_at'])) : '' ?></p>
    </div>
    <a class="button button-primary" href="/chamada">Nova chamada <span aria-hidden="true">+</span></a>
</section>

<div class="summary-stats">
    <div class="card card-lift summary-stat summary-stat-present"><span>Presentes</span><strong><?= (int)$counts['present'] ?></strong></div>
    <div class="card card-lift summary-stat summary-stat-absent"><span>Ausentes</span><strong><?= (int)$counts['absent'] ?></strong></div>
    <div class="card card-lift summary-stat"><span>Alunos na chamada</span><strong><?= (int)$counts['total'] ?></strong></div>
    <?php if ($counts['undone'] > 0): ?>
        <div class="card card-lift summary-stat summary-stat-undone"><span>Faltas desfeitas</span><strong><?= (int)$counts['undone'] ?></strong></div>
    <?php endif; ?>
</div>

<?php if ($summary['without_guardians']): ?>
    <div class="notice notice-warning">Sem responsável ativo para: <?= e(implode(', ', $summary['without_guardians'])) ?>. Ninguém foi avisado sobre essas faltas.</div>
<?php endif; ?>

<section class="summary-section" aria-labelledby="absent-title">
    <div class="section-toolbar">
        <h2 id="absent-title">Alunos marcados como ausentes</h2>
        <span class="muted"><?= count($summary['absences']) ?> aluno(s)</span>
    </div>
    <?php if (!$summary['absences']): ?>
        <div class="card empty-state compact"><span class="empty-icon">✓</span><strong>Ninguém faltou nesta chamada</strong><span>Todos os alunos foram registrados como presentes.</span></div>
    <?php else: ?>
        <div class="absence-cards">
            <?php foreach ($summary['absences'] as $absence): ?>
                <?php $undone = $absence['cancellation'] !== null; ?>
                <article class="card card-lift absence-card<?= $undone ? ' absence-card-undone' : '' ?>">
                    <div class="absence-card-head">
                        <div>
                            <strong><?= e($absence['student_name']) ?></strong>
                            <small><?= e($absence['registration_no'] ?? 'Sem matrícula informada') ?></small>
                        </div>
                        <span class="status status-<?= e(strtolower($absence['status'])) ?>"><?= e($absenceLabels[$absence['status']] ?? $absence['status']) ?></span>
                    </div>
                    <?php if ($undone): $cancel = $absence['cancellation']; ?>
                        <p class="undo-note">Desfeita por <?= e($cancel['cancelled_by_name']) ?><?= $cancel['cancelled_at'] ? ' em ' . e($when($cancel['cancelled_at'])) : '' ?>. Motivo: <?= e($cancel['reason']) ?></p>
                    <?php endif; ?>
                    <?php if (!$absence['communications']): ?>
                        <p class="muted guardian-notices-empty">Nenhum responsável ativo no momento da chamada.</p>
                    <?php else: ?>
                        <ul class="guardian-notices" aria-label="Avisos aos responsáveis de <?= e($absence['student_name']) ?>">
                            <?php foreach ($absence['communications'] as $communication): ?>
                                <li>
                                    <span>
                                        <strong><?= e($communication['guardian_name']) ?></strong>
                                        <small><?= e($channelLabels[$communication['channel']] ?? $communication['channel']) ?><?= $communication['is_correction'] ? ' · correção' : '' ?><?= $communication['is_reminder'] ? ' · lembrete' : '' ?><?= $communication['is_retry'] ? ' · reenvio' : '' ?></small>
                                    </span>
                                    <span class="status status-<?= e(strtolower($communication['status'])) ?>"><?= e($noticeLabels[$communication['status']] ?? $communication['status']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="absence-card-actions">
                        <a class="text-link" href="/ausencias/<?= e($absence['id']) ?>">Ver detalhes <span aria-hidden="true">→</span></a>
                        <?php if (!$undone): ?>
                            <a class="button button-small button-outline" href="/ausencias/<?= e($absence['id']) ?>/desfazer">Desfazer falta</a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<p class="list-note">Os avisos saem pela fila em instantes; recarregue a página para ver a situação atualizada.
    Marcou alguém por engano? Use <strong>Desfazer falta</strong>: avisos ainda na fila são cancelados e quem já recebeu o aviso ganha um e-mail de correção.</p>
