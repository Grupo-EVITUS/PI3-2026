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
 * Reivindica jobs da fila Mongo. SMTP ocorre fora da transação: entrega ao menos uma vez.
 * O aviso de uma falta desfeita não sai mais: é cancelado em vez de enviado ou reagendado.
 * Jobs CANCELADO nunca são reivindicados; a correção da falta desfeita é um e-mail comum.
 */
final class MongoMailQueue
{
    public function __construct(private readonly Database $database)
    {
    }

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
            $job = $this->database->mail_queue->findOneAndUpdate(
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
            // Reivindicado de novo (nova tentativa ou reserva expirada) depois de a falta ser desfeita.
            $withdrawn = MongoAbsenceCancellation::noticeWithdrawn($this->database, $job['communication_id']);
            $success = false;
            if (!$withdrawn) {
                $success = true;
                try {
                    $send(['recipient' => $job['recipient'], 'subject' => $job['subject'], 'body' => $job['body']]);
                } catch (Throwable) {
                    $success = false;
                }
            }
            $session = $this->database->getManager()->startSession();
            try {
                with_transaction($session, function (Session $session) use ($job, $claimId, $success, $withdrawn): void {
                    $now = new UTCDateTime();
                    // Falha com a falta desfeita durante o envio: nada chegou, então cancela em vez de tentar de novo.
                    $cancelled = !$success && ($withdrawn || MongoAbsenceCancellation::noticeWithdrawn(
                        $this->database, $job['communication_id'], $session
                    ));
                    $terminal = !$success && !$cancelled && (int)$job['attempts'] >= 3;
                    $queueStatus = $success ? 'CONCLUIDO'
                        : ($cancelled ? 'CANCELADO' : ($terminal ? 'FALHA' : 'AGUARDANDO'));
                    $nextAttempt = $queueStatus === 'AGUARDANDO'
                        ? new UTCDateTime((time() + 60 * (2 ** ((int)$job['attempts'] - 1))) * 1000)
                        : null;
                    $queueFields = ['status' => $queueStatus, 'started_at' => null,
                        'next_attempt_at' => $nextAttempt, 'processed_at' => $queueStatus === 'AGUARDANDO' ? null : $now];
                    if ($queueStatus !== 'AGUARDANDO') {
                        $queueFields['body'] = '';
                    }
                    $result = $this->database->mail_queue->updateOne(
                        ['_id' => $job['_id'], 'status' => 'PROCESSANDO', 'claim_id' => $claimId],
                        ['$set' => $queueFields, '$unset' => ['claim_id' => '']],
                        ['session' => $session]
                    );
                    if ($result->getMatchedCount() !== 1) {
                        throw new RuntimeException('A reserva da fila expirou durante o envio.');
                    }
                    $communication = $this->database->communications->updateOne(
                        ['_id' => $job['communication_id'], 'status' => 'PENDENTE'],
                        ['$set' => $cancelled
                            ? ['status' => 'CANCELADA', 'cancelled_at' => $now, 'failure_detail' => null]
                            : ['status' => $success ? 'ENVIADO' : ($terminal ? 'FALHA' : 'PENDENTE'),
                                'sent_at' => $success ? $now : null,
                                'failure_detail' => $success ? null : 'Falha na entrega SMTP.']],
                        ['session' => $session]
                    );
                    if ($communication->getMatchedCount() !== 1) {
                        throw new RuntimeException('Comunicação da fila ausente ou já processada.');
                    }
                    (new MongoOperationLogWriter($this->database->operation_logs))->append(
                        null, ['CONCLUIDO' => 'EMAIL_ENVIADO', 'CANCELADO' => 'EMAIL_CANCELADO', 'FALHA' => 'EMAIL_FALHA',
                            'AGUARDANDO' => 'EMAIL_RETRY_AGENDADO'][$queueStatus], 'comunicacao',
                        $job['communication_id'], $cancelled ? 'Falta desfeita pela escola; aviso não enviado.' : '', $session
                    );
                });
            } finally {
                $session->endSession();
            }
            $processed++;
        }
        return $processed;
    }
}
