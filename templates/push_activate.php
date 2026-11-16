<?php $pageTitle = 'Ativar notificações'; ?>
<section class="public-intro">
    <span class="eyebrow">Convite da escola</span>
    <h1>Avisos de ausência no celular</h1>
    <p>Olá, <?= e($guardianName) ?>. A escola convidou você a receber os avisos de ausência como notificação no Android.</p>
</section>
<section class="card public-card">
    <?php if ($pushPanel === null): ?>
        <div class="notice notice-warning" role="status">As notificações ainda não foram configuradas pela escola. Os avisos continuam chegando por e-mail.</div>
    <?php else: ?>
        <?php $pushTitle = 'Ativar neste aparelho'; require __DIR__ . '/push_panel.php'; ?>
        <p class="muted">Este convite pode ser usado uma única vez. Para outro aparelho, peça um novo convite à escola.</p>
    <?php endif; ?>
</section>
