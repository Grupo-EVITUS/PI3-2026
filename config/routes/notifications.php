<?php
declare(strict_types=1);

use Saa\Controllers\WebhookController;
use Saa\Core\Router;

/**
 * Comunicações por e-mail (Etapa 2). O webhook do provedor é público e sem CSRF: a proteção é o cabeçalho
 * X-Email-Webhook-Secret (EMAIL_WEBHOOK_SECRET), conferido pelo WebhookController.
 */
return function (Router $router): void {
    $router->post('/api/webhooks/email', [WebhookController::class, 'email'], [
        'access' => Router::ACCESS_PUBLIC,
        'csrf' => false,
        'api' => true,
        'name' => 'webhooks.email',
    ]);
};
