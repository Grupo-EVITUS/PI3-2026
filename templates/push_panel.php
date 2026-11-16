<?php /** @var array{endpoint: string, vapid_key: string}|null $pushPanel */ ?>
<?php if (!empty($pushPanel)): ?>
<section class="push-panel" data-push-panel
         data-endpoint="<?= e($pushPanel['endpoint']) ?>"
         data-vapid-key="<?= e($pushPanel['vapid_key']) ?>"
         data-csrf="<?= e(Auth::csrfToken()) ?>"
         aria-labelledby="push-panel-title">
    <div class="push-panel-icon" aria-hidden="true">🔔</div>
    <div class="push-panel-text">
        <h2 id="push-panel-title"><?= e($pushTitle ?? 'Receba os avisos no celular') ?></h2>
        <p>Ative as notificações para saber das próximas ausências direto na tela do Android, sem depender do e-mail.
            Se o aviso não chegar ao aparelho, a escola continua enviando por e-mail.</p>
        <button type="button" class="button button-primary" data-push-subscribe>Ativar notificações neste celular</button>
        <p class="push-status" data-push-status role="status" aria-live="polite"></p>
        <details class="push-help">
            <summary>Como instalar o app na tela inicial</summary>
            <ol>
                <li>Abra este link no <strong>Google Chrome</strong> do Android.</li>
                <li>Toque em <strong>Ativar notificações neste celular</strong> e depois em <strong>Permitir</strong>.</li>
                <li>No menu <strong>⋮</strong> do Chrome, toque em <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>.</li>
            </ol>
            <p class="muted">Para parar de receber, desative as notificações do app Presença nas configurações do Android.</p>
        </details>
    </div>
</section>
<?php endif; ?>
