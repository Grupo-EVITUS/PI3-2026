<?php
$pageTitle = 'Chamada registrada';
$crumbs = [['Chamada', '/chamada'], ['Registrada', null]];
$pushCount = (int)($result['push_notifications'] ?? 0);
$queued = (int)$result['notifications'];
$day = e(date('d/m/Y', strtotime($date)));
?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Chamada concluída</span>
        <h1>Chamada registrada</h1>
        <p><?= (int)$result['attendance_counts']['total'] ?> aluno(s):
            <?= (int)$result['attendance_counts']['present'] ?> presente(s) e
            <?= (int)$result['attendance_counts']['absent'] ?> ausente(s), em <?= $day ?>.</p>
    </div>
    <a class="button button-primary" href="/chamada">Nova chamada</a>
</section>

<div class="stat-grid">
    <div class="stat stat-ok"><strong><?= (int)$result['attendance_counts']['present'] ?></strong><span>presentes</span></div>
    <div class="stat stat-warn"><strong><?= (int)$result['attendance_counts']['absent'] ?></strong><span>ausentes</span></div>
    <div class="stat"><strong><?= $queued ?></strong><span>aviso(s) na fila</span></div>
</div>

<section class="card next-steps">
    <h2>O que acontece agora</h2>
    <ol>
        <li><?= $queued ?> comunicação(ões) aguardam na fila:
            <?= $pushCount ?> por notificação push e
            <?= $queued - $pushCount ?> por e-mail.</li>
        <li><strong>O registro não envia avisos diretamente.</strong> Os workers de push e e-mail enviam em seguida;
            push não entregue é reencaminhado por e-mail. O link de justificativa funciona depois do envio.</li>
        <li>Acompanhe as respostas em <a class="text-link" href="/ocorrencias?de=<?= e($date) ?>&amp;ate=<?= e($date) ?>">Ocorrências desta data</a>.</li>
    </ol>
</section>
<?php if ($result['without_guardians']): ?>
    <div class="notice notice-warning">Sem responsável ativo para: <?= e(implode(', ', $result['without_guardians'])) ?>.</div>
<?php endif; ?>
<p class="list-note">Código da chamada: <code><?= e($result['attendance_session_id']) ?></code></p>
