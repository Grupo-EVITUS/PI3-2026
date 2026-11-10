<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Notifications;

use Saa\Core\App;
use Saa\Core\Config;
use Saa\Core\Request;
use Saa\Tests\Support\MongoTestCase;

/**
 * Webhook de e-mail sem segredo configurado (issue #43, RNF-04): passa pelo Kernel real (App::handle) no próprio
 * processo, com EMAIL_WEBHOOK_SECRET ausente — o servidor dos testes de aceitação já sobe COM o segredo.
 */
final class WebhookConfigurationTest extends MongoTestCase
{
    protected function tearDown(): void
    {
        Config::set('EMAIL_WEBHOOK_SECRET', null);
        parent::tearDown();
    }

    private function post(?string $secret): array
    {
        $server = $secret === null ? [] : ['HTTP_X_EMAIL_WEBHOOK_SECRET' => $secret];
        $response = App::boot()->handle(Request::create('POST', '/api/webhooks/email', [], $server, [], '{"event":"DELIVERED","provider_message_id":"x"}'));
        return [$response->status(), json_decode($response->body(), true), $response->header('Content-Type')];
    }

    public function testIssue43WebhookIsDisabledWith503WhenNoSecretIsConfigured(): void
    {
        Config::set('EMAIL_WEBHOOK_SECRET', null); // ignora um eventual .env local

        foreach ([null, '', 'qualquer-coisa'] as $secret) {
            [$status, $body, $type] = $this->post($secret);
            self::assertSame(503, $status);
            self::assertSame('webhook_disabled', $body['error']['code'] ?? null);
            self::assertStringContainsString('application/json', (string)$type);
        }
    }

    public function testIssue43RouteIsPublicWithoutCsrfAndAnsweredInJson(): void
    {
        Config::set('EMAIL_WEBHOOK_SECRET', 'segredo-de-teste-123');

        [$status, $body] = $this->post('errado');
        self::assertSame(401, $status, 'sem sessão e sem token CSRF: não é 403 de CSRF nem redirecionamento para /login');
        self::assertSame('unauthenticated', $body['error']['code'] ?? null);

        [$status, $body] = $this->post('segredo-de-teste-123');
        self::assertSame(404, $status);
        self::assertSame('Notificação não encontrada.', $body['error']['message'] ?? null);
    }
}
