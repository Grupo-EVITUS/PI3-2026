<?php $pageTitle = 'Convite de notificações'; ?>
<section class="page-heading">
    <div>
        <span class="eyebrow">Cadastros</span>
        <h1>Convite de notificações</h1>
        <p>Entregue este link a <strong><?= e($guardianName) ?></strong> (por exemplo, por mensagem ou impresso na matrícula).
            O responsável deve abri-lo no Chrome do celular Android.</p>
    </div>
    <a class="button button-quiet" href="/cadastros">Voltar aos cadastros</a>
</section>
<section class="card">
    <label class="invite-link">Link de ativação
        <input type="text" readonly value="<?= e($inviteUrl) ?>" aria-describedby="invite-help">
    </label>
    <p id="invite-help" class="muted">Válido por 7 dias e para um único aparelho. Este link não é exibido novamente;
        se perder, gere outro convite. Enquanto o responsável não ativar, os avisos continuam por e-mail.</p>
</section>
