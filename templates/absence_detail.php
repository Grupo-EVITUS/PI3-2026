<?php $pageTitle = 'Detalhes da ausência'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Ocorrência #<?= (int)$absence['id'] ?></span>
        <h1><?= e($absence['student_name']) ?></h1>
        <p><?= e($absence['class_name']) ?> · <?= e($absence['grade']) ?> · ausência em <?= e(date('d/m/Y', strtotime($absence['absence_date']))) ?></p>
    </div>
    <a class="button button-quiet" href="/ocorrencias">Voltar à lista</a>
</section>

<div class="detail-grid">
    <section class="card">
        <div class="section-toolbar">
            <h2>Comunicações aos responsáveis</h2>
            <span class="status status-<?= e(strtolower($absence['status'])) ?>"><?= e(ucfirst(strtolower($absence['status']))) ?></span>
        </div>
        <?php if (!$communications): ?>
            <div class="notice notice-warning">Não havia responsável com e-mail cadastrado no momento do registro.</div>
        <?php else: ?>
            <div class="result-list">
                <?php foreach ($communications as $communication): ?>
                    <article class="result-row">
                        <div>
                            <strong><?= e($communication['guardian_name']) ?></strong>
                            <small><?= e($communication['email_to']) ?></small>
                            <?php if ($communication['failure_detail']): ?><small class="error-text"><?= e($communication['failure_detail']) ?></small><?php endif; ?>
                        </div>
                        <span class="status status-<?= e(strtolower($communication['status'])) ?>"><?= e(ucfirst(strtolower($communication['status']))) ?></span>
                        <?php if ($communication['status'] === 'FALHA' && in_array($_SESSION['profile'], ['AOE', 'COORDENACAO'], true)): ?>
                            <form method="post" action="/comunicacoes/reenvio">
                                <input type="hidden" name="csrf_token" value="<?= e(Auth::csrfToken()) ?>">
                                <input type="hidden" name="communication_id" value="<?= (int)$communication['id'] ?>">
                                <button class="button button-small button-outline" type="submit">Reenviar</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <dl class="detail-meta">
            <div><dt>Registrada por</dt><dd><?= e($absence['registered_by_name']) ?></dd></div>
            <div><dt>Registrada em</dt><dd><?= e(date('d/m/Y H:i', strtotime($absence['created_at']))) ?></dd></div>
        </dl>
    </section>

    <section class="card">
        <h2>Justificativa</h2>
        <?php if (!$justification): ?>
            <div class="empty-state compact"><span class="empty-icon">…</span><strong>Aguardando resposta</strong><span>O status muda quando o primeiro responsável responder.</span></div>
        <?php else: ?>
            <div class="justification-card">
                <span class="status status-respondida">Respondida</span>
                <p class="reason-label"><?= e($reasons[$justification['reason']] ?? $justification['reason']) ?></p>
                <?php if ($justification['explanation']): ?><p><?= nl2br(e($justification['explanation'])) ?></p><?php endif; ?>
                <p class="muted">Enviada por <?= e($justification['guardian_name']) ?> em <?= e(date('d/m/Y H:i', strtotime($justification['submitted_at']))) ?></p>
            </div>
        <?php endif; ?>
    </section>
</div>
