<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use MongoDB\Operation\FindOneAndUpdate;
use RuntimeException;
use Throwable;
use function MongoDB\with_transaction;

/**
 * Entrega a fila de push aos aparelhos do responsável. Sem aparelho que aceite a mensagem,
 * o aviso é encaminhado à fila de e-mail na mesma transação: o responsável nunca fica sem aviso.
 * Entrega ao menos uma vez; a tag da notificação (ID da comunicação) agrupa duplicatas no aparelho.
 */
final class MongoPushQueue
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @param callable(array{endpoint: string, p256dh: string, auth: string}, string, string): int $send
     *        recebe inscrição, payload JSON e tópico; devolve o status HTTP do serviço de push.
     */
    public function process(callable $send, int $limit = 20): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Limite de processamento inválido.');
        }
        $processed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $now = new UTCDateTime();
            $stale = new UTCDateTime((time() - 300) * 1000);
            $claimId = new ObjectId();
            $job = $this->database->push_queue->findOneAndUpdate(
                ['$or' => [
                    ['status' => 'AGUARDANDO', '$or' => [
                        ['next_attempt_at' => null], ['next_attempt_at' => ['$lte' => $now]],
                    ]],
                    ['status' => 'PROCESSANDO', 'started_at' => ['$lt' => $stale]],
                ]],
                ['$set' => ['status' => 'PROCESSANDO', 'started_at' => $now, 'claim_id' => $claimId],
                 '$inc' => ['attempts' => 1]],
                ['sort' => ['created_at' => 1, '_id' => 1],
                 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if ($job === null) {
                break;
            }
            $outcome = $this->deliver($job, $send);
            $session = $this->database->getManager()->startSession();
            try {
                with_transaction($session, function (Session $session) use ($job, $claimId, $outcome): void {
                    $this->settle($job, $claimId, $outcome, $session);
                });
            } finally {
                $session->endSession();
            }
            $processed++;
        }
        return $processed;
    }

    /** @return array{delivered: list<ObjectId>, gone: list<ObjectId>, transient: int, permanent: int, detail: string} */
    private function deliver(array $job, callable $send): array
    {
        $outcome = ['delivered' => [], 'gone' => [], 'transient' => 0, 'permanent' => 0, 'detail' => ''];
        $subscriptions = $this->database->push_subscriptions->find(
            ['guardian_id' => $job['guardian_id'], 'active' => true],
            ['sort' => ['created_at' => -1], 'limit' => MongoPushSubscriptions::MAX_ACTIVE_PER_GUARDIAN]
        )->toArray();
        $payload = json_encode([
            'title' => $job['title'], 'body' => $job['body'], 'url' => $job['url'],
            'tag' => 'saa-' . (string)$job['communication_id'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subscriptions as $subscription) {
            try {
                $status = (int)$send([
                    'endpoint' => $subscription['endpoint'], 'p256dh' => $subscription['p256dh'],
                    'auth' => $subscription['auth'],
                ], $payload, substr((string)$job['communication_id'], -24));
            } catch (Throwable $error) {
                $outcome['transient']++;
                $outcome['detail'] = 'Serviço de push indisponível.';
                continue;
            }
            if ($status >= 200 && $status < 300) {
                $outcome['delivered'][] = $subscription['_id'];
            } elseif ($status === 404 || $status === 410) {
                // Permissão revogada, app desinstalado ou inscrição expirada.
                $outcome['gone'][] = $subscription['_id'];
            } elseif ($status === 429 || $status >= 500) {
                $outcome['transient']++;
                $outcome['detail'] = 'Serviço de push respondeu ' . $status . '.';
            } else {
                $outcome['permanent']++;
                $outcome['detail'] = 'Serviço de push recusou a mensagem (' . $status . ').';
            }
        }
        return $outcome;
    }

    private function settle(array $job, ObjectId $claimId, array $outcome, Session $session): void
    {
        $options = ['session' => $session];
        $now = new UTCDateTime();
        $attempts = (int)$job['attempts'];
        $delivered = $outcome['delivered'] !== [];
        $retry = !$delivered && $outcome['transient'] > 0 && $attempts < self::MAX_ATTEMPTS;
        $status = $delivered ? 'CONCLUIDO' : ($retry ? 'AGUARDANDO' : 'ENCAMINHADO_EMAIL');
        $fallback = $job['fallback_email'] ?? null;
        if ($status === 'ENCAMINHADO_EMAIL' && $fallback === null) {
            throw new RuntimeException('Aviso sem e-mail de reserva; a fila de push foi preservada.');
        }

        $fields = ['status' => $status, 'started_at' => null,
            'next_attempt_at' => $retry ? new UTCDateTime((time() + 60 * (2 ** ($attempts - 1))) * 1000) : null,
            'processed_at' => $retry ? null : $now,
            'failure_detail' => $delivered ? null : ($outcome['detail'] !== '' ? $outcome['detail'] : 'Nenhum aparelho ativo.')];
        if (!$retry) {
            // Conteúdo com link de justificativa não fica na fila após o desfecho.
            $fields += ['body' => '', 'url' => '', 'fallback_email' => null];
        }
        $result = $this->database->push_queue->updateOne(
            ['_id' => $job['_id'], 'status' => 'PROCESSANDO', 'claim_id' => $claimId],
            ['$set' => $fields, '$unset' => ['claim_id' => '']], $options
        );
        if ($result->getMatchedCount() !== 1) {
            throw new RuntimeException('A reserva da fila de push expirou durante o envio.');
        }

        if ($outcome['delivered'] !== []) {
            $this->database->push_subscriptions->updateMany(
                ['_id' => ['$in' => $outcome['delivered']]], ['$set' => ['last_success_at' => $now]], $options
            );
        }
        if ($outcome['gone'] !== []) {
            $this->database->push_subscriptions->updateMany(
                ['_id' => ['$in' => $outcome['gone']], 'active' => true],
                ['$set' => ['active' => false, 'revoked_at' => $now, 'revoke_reason' => 'EXPIRADA_NO_SERVICO']],
                $options
            );
        }

        $communication = ['_id' => $job['communication_id'], 'status' => 'PENDENTE'];
        if ($status === 'CONCLUIDO') {
            $update = ['status' => 'ENVIADO', 'sent_at' => $now, 'failure_detail' => null];
        } elseif ($status === 'ENCAMINHADO_EMAIL') {
            $update = ['channel' => 'EMAIL', 'failure_detail' => 'Push não entregue; aviso encaminhado por e-mail.'];
            $this->database->mail_queue->insertOne([
                '_id' => new ObjectId(), 'communication_id' => $job['communication_id'],
                'recipient' => $fallback['recipient'], 'subject' => $fallback['subject'], 'body' => $fallback['body'],
                'status' => 'AGUARDANDO', 'created_at' => $now,
            ], $options);
        } else {
            $update = ['failure_detail' => $fields['failure_detail']];
        }
        if ($this->database->communications->updateOne($communication, ['$set' => $update], $options)
            ->getMatchedCount() !== 1) {
            throw new RuntimeException('Comunicação da fila de push ausente ou já processada.');
        }
        (new MongoOperationLogWriter($this->database->operation_logs))->append(
            null,
            ['CONCLUIDO' => 'PUSH_ENVIADO', 'AGUARDANDO' => 'PUSH_RETRY_AGENDADO',
                'ENCAMINHADO_EMAIL' => 'PUSH_ENCAMINHADO_EMAIL'][$status],
            'comunicacao', $job['communication_id'],
            count($outcome['delivered']) . ' aparelho(s) entregue(s); ' . count($outcome['gone']) . ' desativado(s)',
            $session
        );
    }
}
