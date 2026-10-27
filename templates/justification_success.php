<?php $pageTitle = 'Resposta registrada'; ?>
<section class="success-page">
    <div class="success-mark" aria-hidden="true">✓</div>
    <span class="eyebrow">Tudo certo</span>
    <h1>Justificativa enviada</h1>
    <p>A resposta sobre a ausência de <strong><?= e($absence['student_name']) ?></strong>, em <?= e(date('d/m/Y', strtotime($absence['absence_date']))) ?>, foi registrada.</p>
    <p class="muted">A escola já pode consultar as informações enviadas.</p>
</section>
