<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance;

use MongoDB\BSON\ObjectId;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Tests\Integration\Notifications\SchoolFixtures;
use Saa\Tests\Support\HttpResponse;
use Saa\Tests\Support\HttpTestCase;

/**
 * POST /api/webhooks/email ponta a ponta (issue #43): segredo no cabeçalho (401), 404, 422, progressão de status sem
 * regressão (SENT < DELIVERED < OPENED; FAILED não sobrescreve DELIVERED/OPENED), busca por provider_message_id e
 * limite de 30 eventos. O caso "sem segredo configurado → 503" está em WebhookConfigurationTest.
 */
final class WebhookTest extends HttpTestCase
{
    use SchoolFixtures;

    private const SECRET = 'segredo-do-webhook-de-teste-0123456789';

    protected static function serverEnvironment(): array
    {
        return ['EMAIL_WEBHOOK_SECRET' => self::SECRET];
    }

    /** @param array<string, mixed>|string $payload */
    private function hook(array|string $payload, ?string $secret = self::SECRET): HttpResponse
    {
        $headers = $secret === null ? [] : ['X-Email-Webhook-Secret' => $secret];
        return $this->http->postJson('/api/webhooks/email', is_string($payload) ? $payload : (string)json_encode($payload), $headers);
    }

    public function testIssue43MissingOrWrongSecretIsRejectedWith401(): void
    {
        $id = $this->seedNotification(['status' => 'SENT']);
        $payload = ['notification_id' => (string)$id, 'event' => 'OPENED'];

        foreach ([null, '', 'segredo-errado', self::SECRET . 'x', strtoupper(self::SECRET)] as $secret) {
            $response = $this->hook($payload, $secret);
            self::assertSame(401, $response->status, var_export($secret, true));
            self::assertStringContainsString('application/json', $response->contentType());
            self::assertSame('unauthenticated', $response->json()['error']['code'] ?? null);
        }
        self::assertSame('SENT', $this->rawNotification($id)['status'], 'nada muda sem o segredo');
    }

    public function testIssue43UnknownNotificationIs404(): void
    {
        $byId = $this->hook(['notification_id' => (string)new ObjectId(), 'event' => 'DELIVERED']);
        $byProvider = $this->hook(['provider_message_id' => 'nao-existe@provedor', 'event' => 'DELIVERED']);

        foreach ([$byId, $byProvider] as $response) {
            self::assertSame(404, $response->status);
            self::assertSame('Notificação não encontrada.', $response->json()['error']['message'] ?? null);
        }
    }

    public function testIssue43InvalidPayloadIs422WithFieldMessages(): void
    {
        $id = (string)$this->seedNotification(['status' => 'SENT']);
        $cases = [
            'evento desconhecido' => [['notification_id' => $id, 'event' => 'CLICKED'], 'event'],
            'sem evento' => [['notification_id' => $id], 'event'],
            'sem identificação' => [['event' => 'DELIVERED'], 'notification_id'],
            'id malformado' => [['notification_id' => 'abc', 'event' => 'DELIVERED'], 'notification_id'],
            'operador NoSQL no id' => [['notification_id' => ['$ne' => null], 'event' => 'DELIVERED'], 'notification_id'],
            'operador NoSQL no provedor' => [['provider_message_id' => ['$gt' => ''], 'event' => 'DELIVERED'], 'provider_message_id'],
            'data inválida' => [['notification_id' => $id, 'event' => 'DELIVERED', 'occurred_at' => 'ontem'], 'occurred_at'],
            'detalhe longo' => [['notification_id' => $id, 'event' => 'FAILED', 'detail' => str_repeat('x', 501)], 'detail'],
        ];
        foreach ($cases as $name => [$payload, $field]) {
            $response = $this->hook($payload);
            self::assertSame(422, $response->status, $name);
            self::assertSame('validation_error', $response->json()['error']['code'] ?? null, $name);
            self::assertArrayHasKey($field, $response->json()['error']['fields'] ?? [], $name);
        }
        self::assertSame(400, $this->hook('{"event": "DELIVERED",')->status, 'JSON malformado');
        self::assertSame([], $this->rawNotification(new ObjectId($id))['events']);
    }

    public function testIssue43StatusProgressesWithoutRegressingAndEveryEventIsKept(): void
    {
        $id = $this->seedNotification(['status' => 'SENT', 'attempts' => 1, 'provider_message_id' => 'abc-123@provedor']);
        $steps = [
            [['provider_message_id' => 'abc-123@provedor', 'event' => 'DELIVERED', 'occurred_at' => '2026-10-09T12:01:04Z'], 'DELIVERED'],
            [['notification_id' => (string)$id, 'event' => 'SENT'], 'DELIVERED'],
            [['notification_id' => (string)$id, 'event' => 'OPENED', 'occurred_at' => '2026-10-09T10:20:00-03:00'], 'OPENED'],
            [['notification_id' => (string)$id, 'event' => 'DELIVERED'], 'OPENED'],
            [['notification_id' => (string)$id, 'event' => 'FAILED', 'detail' => 'Caixa cheia'], 'OPENED'],
        ];
        foreach ($steps as $i => [$payload, $expected]) {
            $response = $this->hook($payload);
            self::assertSame(200, $response->status, "passo {$i}: " . $response->body);
            self::assertSame($expected, $response->json()['data']['status'] ?? null, "passo {$i}");
            self::assertSame((string)$id, $response->json()['data']['id'] ?? null);
        }

        $doc = $this->rawNotification($id);
        self::assertSame('OPENED', $doc['status']);
        self::assertNull($doc['last_error'], 'FAILED depois de OPENED não muda o status nem o erro');
        self::assertSame(['DELIVERED', 'SENT', 'OPENED', 'DELIVERED', 'FAILED'], array_column($doc['events'], 'type'), 'o histórico guarda todos os eventos');
        self::assertSame('2026-10-09T12:01:04Z', Bson::toPlain($doc['events'][0]['occurred_at']));
        self::assertSame('2026-10-09T13:20:00Z', Bson::toPlain($doc['events'][2]['occurred_at']), 'fuso convertido para UTC');
        self::assertSame('Caixa cheia', $doc['events'][4]['detail']);
        self::assertSame(5, Mongo::collection(Collections::AUDIT_EVENTS)->countDocuments(['action' => 'notification.provider_event']));
    }

    public function testIssue43FailedEventMarksASentMessageAsFailedButNeverADeliveredOne(): void
    {
        $sent = $this->seedNotification(['status' => 'SENT', 'attempts' => 1]);
        $delivered = $this->seedNotification(['status' => 'DELIVERED', 'attempts' => 1]);

        $first = $this->hook(['notification_id' => (string)$sent, 'event' => 'FAILED', 'detail' => '550 Caixa postal inexistente']);
        $second = $this->hook(['notification_id' => (string)$delivered, 'event' => 'FAILED', 'detail' => 'atraso']);

        self::assertSame('FAILED', $first->json()['data']['status'] ?? null);
        self::assertSame('550 Caixa postal inexistente', $this->rawNotification($sent)['last_error']);
        self::assertNull($this->rawNotification($sent)['next_attempt_at'], 'falha informada pelo provedor não reagenda envio');
        self::assertSame('DELIVERED', $second->json()['data']['status'] ?? null);
    }

    public function testIssue43EventHistoryIsLimitedToThirtyItems(): void
    {
        $id = $this->seedNotification(['status' => 'SENT', 'attempts' => 1]);

        for ($i = 1; $i <= 32; $i++) {
            self::assertSame(200, $this->hook(['notification_id' => (string)$id, 'event' => 'OPENED', 'detail' => "abertura {$i}"])->status);
        }

        $events = $this->rawNotification($id)['events'];
        self::assertCount(30, $events);
        self::assertSame('abertura 3', $events[0]['detail']);
        self::assertSame('abertura 32', $events[29]['detail']);
        self::assertSame(30, $this->hook(['notification_id' => (string)$id, 'event' => 'OPENED'])->json()['data']['events'] ?? null);
    }

    public function testIssue43WebhookNeverExposesTheTokenHash(): void
    {
        $id = $this->seedNotification(['status' => 'SENT']);
        $hash = $this->rawNotification($id)['justification_link']['token_hash'];

        $response = $this->hook(['notification_id' => (string)$id, 'event' => 'DELIVERED']);

        self::assertSame(200, $response->status);
        self::assertStringNotContainsString($hash, $response->body);
        self::assertStringNotContainsString('token_hash', $response->body);
    }
}
