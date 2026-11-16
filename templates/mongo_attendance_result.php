<?php $pageTitle = 'Chamada MongoDB registrada'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Homologação MongoDB</span>
        <h1>Chamada registrada</h1>
        <p><?= (int)$result['attendance_counts']['total'] ?> aluno(s):
            <?= (int)$result['attendance_counts']['present'] ?> presente(s) e
            <?= (int)$result['attendance_counts']['absent'] ?> ausente(s), em <?= e(date('d/m/Y', strtotime($date))) ?>.</p>
    </div>
    <a class="button button-quiet" href="/chamada">Nova chamada</a>
</section>
<div class="notice notice-warning" role="status">
    <?= (int)$result['notifications'] ?> comunicação(ões) aguardam na fila MongoDB:
    <?= (int)($result['push_notifications'] ?? 0) ?> por notificação push e
    <?= (int)$result['notifications'] - (int)($result['push_notifications'] ?? 0) ?> por e-mail.
    <strong>O registro não envia avisos diretamente.</strong> Os workers de push e SMTP processarão as filas;
    push não entregue é reencaminhado por e-mail. O link de justificativa funcionará após o envio.
</div>
<?php if ($result['without_guardians']): ?>
    <div class="notice notice-warning">Sem responsável ativo para: <?= e(implode(', ', $result['without_guardians'])) ?>.</div>
<?php endif; ?>
<section class="card">
    <h2>Registro de teste</h2>
    <p>Identificador da chamada: <code><?= e($result['attendance_session_id']) ?></code></p>
</section>
