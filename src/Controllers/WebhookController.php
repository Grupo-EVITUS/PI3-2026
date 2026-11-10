<?php
declare(strict_types=1);

namespace Saa\Controllers;

use Saa\Core\Config;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Database\Bson;
use Saa\Services\NotificationService;

/**
 * POST /api/webhooks/email — eventos do provedor de e-mail (issue #43; RNF-04).
 *
 * Segurança: rota pública e sem CSRF (quem chama é o provedor), protegida pelo cabeçalho X-Email-Webhook-Secret,
 * comparado com hash_equals a EMAIL_WEBHOOK_SECRET. Sem segredo configurado → 503 (o webhook fica desligado);
 * cabeçalho ausente ou errado → 401. O segredo é conferido ANTES de ler o corpo.
 *
 * Corpo JSON: {"notification_id" | "provider_message_id", "event": SENT|DELIVERED|OPENED|FAILED, "occurred_at"?, "detail"?}
 * Respostas: 200 {"data": {id, status, event}} · 400 JSON malformado · 404 notificação inexistente · 422 dados inválidos.
 */
final class WebhookController extends Controller
{
    public const SECRET_HEADER = 'X-Email-Webhook-Secret';

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function email(Request $request): Response
    {
        $secret = Config::string('EMAIL_WEBHOOK_SECRET');
        if ($secret === '') {
            return $this->jsonError('webhook_disabled', 'O webhook de e-mail não está configurado (EMAIL_WEBHOOK_SECRET).', 503);
        }
        $received = $request->header(self::SECRET_HEADER) ?? '';
        if ($received === '' || !hash_equals($secret, $received)) {
            return $this->jsonError('unauthenticated', 'Segredo do webhook ausente ou inválido.', 401);
        }

        $body = $request->body();
        $notification = $this->notifications->applyProviderEvent($body);
        return $this->json([
            'id' => Bson::id($notification['_id']),
            'status' => (string)$notification['status'],
            'event' => (string)($body['event'] ?? ''),
            'events' => count((array)($notification['events'] ?? [])),
        ]);
    }
}
