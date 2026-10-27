<?php $pageTitle = $title ?? 'Erro'; ?>
<section class="error-page card">
    <span class="eyebrow">Presença</span>
    <h1><?= e($title ?? 'Não foi possível continuar') ?></h1>
    <p><?= e($message ?? 'Ocorreu um erro ao processar sua solicitação.') ?></p>
    <a class="button button-quiet" href="<?= isset($_SESSION['user_id']) ? '/ocorrencias' : '/login' ?>">Voltar</a>
</section>
