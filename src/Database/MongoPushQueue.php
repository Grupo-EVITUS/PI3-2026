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
 * O 2xx do serviço de push indica aceite, não leitura: se a ausência seguir sem justificativa
 * depois de $reminderHours horas, o responsável recebe também um lembrete por e-mail.
 */
final class MongoPushQueue
{
    public const MAX_ATTEMPTS = 3;
    public const DEFAULT_REMINDER_HOURS = 24;
    public const MAX_REMINDER_HOURS = 168;

    /** @param int $reminderHours horas até o lembrete por e-mail; 0 desliga o lembrete */
    public function __construct(
        private readonly Database $database, private readonly int $reminderHours = self::DEFAULT_REMINDER_HOURS
    ) {
        if ($reminderHours < 0 || $reminderHours > self::MAX_REMINDER_HOURS) {
            throw new \InvalidArgumentException('Prazo do lembrete por e-mail inválido (0 a 168 horas).');
        }
    }

    /** Lê PUSH_EMAIL_REMINDER_HOURS (padrão 24; 0 desliga). */
    public static function reminderHoursFromEnvironment(): int
    {
        $raw = getenv('PUSH_EMAIL_REMINDER_HOURS');
        if ($raw === false || trim($raw) === '') {
            return self::DEFAULT_REMINDER_HOURS;
        }
        if (preg_match('/^\d{1,3}$/', trim($raw)) !== 1 || (int)$raw > self::MAX_REMINDER_HOURS) {
            throw new \InvalidArgumentException('PUSH_EMAIL_REMINDER_HOURS deve ser um número de 0 a 168.');
        }
        return (int)$raw;
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

    /**
     * Lembrete por e-mail dos pushes entregues cujo prazo venceu sem justificativa.
     * Cria nova comunicação por e-mail (novo link) ligada à do push por reminder_of_id;
     * ausência já respondida, responsável arquivado ou sem dados para o link dispensam o lembrete.
     */
    public function remindUnanswered(int $limit = 20): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Limite de processamento inválido.');
        }
        $processed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $job = $this->database->push_queue->findOne(
                ['status' => 'CONCLUIDO', 'reminder_due_at' => ['$lte' => new UTCDateTime()]],
                ['sort' => ['reminder_due_at' => 1, '_id' => 1]]
            );
            if ($job === null) {
                break;
            }
            $session = $this->database->getManager()->startSession();
            try {
                with_transaction($session, function (Session $session) use ($job): void {
                    $this->remind($job, $session);
                });
            } finally {
                $session->endSession();
            }
            $processed++;
        }
        return $processed;
    }

    private function remind(array $job, Session $session): void
    {
        $options = ['session' => $session];
        $now = new UTCDateTime();
        // Trava contra dois workers: só um consegue apagar o prazo vencido.
        if ($this->database->push_queue->updateOne(
            ['_id' => $job['_id'], 'status' => 'CONCLUIDO', 'reminder_due_at' => ['$lte' => $now]],
            ['$set' => ['reminder_due_at' => null]], $options
        )->getMatchedCount() !== 1) {
            return;
        }
        $communication = $this->database->communications->findOne(['_id' => $job['communication_id']], $options);
        $absence = $communication === null ? null
            : $this->database->absences->findOne(['_id' => $communication['absence_id']], $options);
        $guardian = $this->database->guardians->findOne(['_id' => $job['guardian_id'], 'active' => true], $options);
        $student = $absence === null ? null
            : $this->database->students->findOne(['_id' => $absence['student_id']], $options);
        $baseUrl = $job['base_url'] ?? null;

        $reason = match (true) {
            $absence === null || $student === null => 'Ausência não encontrada.',
            $absence['status'] !== 'PENDENTE' => 'Ausência já respondida.',
            $guardian === null => 'Responsável arquivado.',
            !filter_var($guardian['email'], FILTER_VALIDATE_EMAIL) => 'E-mail do responsável inválido.',
            !is_string($baseUrl) || $baseUrl === '' => 'Aviso sem endereço público para o link.',
            default => null,
        };
        if ($reason === null) {
            $className = null;
            if (($absence['attendance_session_id'] ?? null) instanceof ObjectId) {
                $className = $this->database->attendance_sessions->findOne(
                    ['_id' => $absence['attendance_session_id']], $options
                )['class_name'] ?? null;
            }
            if ($className === null && ($absence['class_id'] ?? null) instanceof ObjectId) {
                $className = $this->database->classes->findOne(['_id' => $absence['class_id']], $options)['name'] ?? null;
            }
            // Disputa com arquivamento/edição do destinatário durante a transação.
            $this->database->guardians->updateOne(
                ['_id' => $guardian['_id'], 'active' => true], ['$inc' => ['link_revision' => 1]], $options
            );
            // Sempre por e-mail: o push já foi entregue e ficou sem resposta.
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $reminderId = new ObjectId();
            $this->database->communications->insertOne([
                '_id' => $reminderId, 'absence_id' => $absence['_id'], 'guardian_id' => $guardian['_id'],
                'email_to' => $guardian['email'], 'token_hash' => hash('sha256', $token),
                'status' => 'PENDENTE', 'created_at' => $now, 'channel' => 'EMAIL',
                'reminder_of_id' => $communication['_id'],
            ], $options);
            $this->database->mail_queue->insertOne([
                '_id' => new ObjectId(), 'communication_id' => $reminderId,
                'status' => 'AGUARDANDO', 'created_at' => $now,
            ] + AbsenceNotice::email(
                $guardian['email'], $guardian['name'], $student['name'], $className ?? 'não informada',
                $absence['absence_date'], PublicUrl::normalize($baseUrl) . '/justificar/' . rawurlencode($token),
                reminder: true
            ), $options);
        }
        $this->database->push_queue->updateOne(
            ['_id' => $job['_id']],
            ['$set' => ['reminder_status' => $reason === null ? 'EMAIL_ENFILEIRADO' : 'DISPENSADO', 'base_url' => null]],
            $options
        );
        (new MongoOperationLogWriter($this->database->operation_logs))->append(
            null, $reason === null ? 'PUSH_LEMBRETE_EMAIL' : 'PUSH_LEMBRETE_DISPENSADO', 'comunicacao',
            $job['communication_id'], $reason ?? 'Push entregue sem justificativa; lembrete enviado por e-mail.',
            $session
        );
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
            $remind = $delivered && $this->reminderHours > 0;
            $fields['reminder_due_at'] = $remind ? new UTCDateTime((time() + 3600 * $this->reminderHours) * 1000) : null;
            if (!$remind) {
                $fields['base_url'] = null;
            }
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
