<?php
declare(strict_types=1);

namespace Saa\Database;

use DomainException;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use RuntimeException;
use function MongoDB\with_transaction;

/**
 * Desfaz uma falta marcada por engano depois de a chamada ter sido enviada. Na mesma transação:
 * a ausência fica CANCELADA, a chamada passa a contar presença, os avisos que ainda não saíram da
 * fila são cancelados e cada responsável que pode ter recebido o aviso ganha um e-mail de correção.
 * Os workers consultam noticeWithdrawn() para nunca entregar o aviso de uma falta desfeita.
 */
final class MongoAbsenceCancellation
{
    public const DEFAULT_REASON = 'Falta marcada por engano.';
    public const MAX_REASON_LENGTH = 300;
    /** Situações da fila em que o aviso ainda não saiu (QUARENTENA: legado que nunca é enviado). */
    private const NOT_SENT = ['AGUARDANDO', 'QUARENTENA'];

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{absence_id: string, attendance_session_id: ?string, cancelled_notices: int, corrections: int}
     */
    public function cancel(ObjectId $actor, ObjectId $absenceId, string $reason = ''): array
    {
        $reason = self::normalizeReason($reason);
        $session = $this->database->getManager()->startSession();
        try {
            $result = null;
            with_transaction($session, function (Session $session) use ($actor, $absenceId, $reason, &$result): void {
                $options = ['session' => $session];
                if ($this->database->users->countDocuments(
                    ['_id' => $actor, 'active' => true, 'profile' => ['$in' => ['AOE', 'COORDENACAO']]], $options
                ) !== 1) {
                    throw new RuntimeException('Desfazer falta é permitido somente à equipe autorizada e ativa.');
                }
                $absence = $this->database->absences->findOne(['_id' => $absenceId], $options);
                if ($absence === null) {
                    throw new InvalidArgumentException('Falta não encontrada.');
                }
                if ($absence['status'] === 'CANCELADA') {
                    throw new DomainException('Esta falta já foi desfeita.');
                }
                $now = new UTCDateTime();
                // Trava contra dois pedidos simultâneos e contra a justificativa chegando agora.
                if ($this->database->absences->updateOne(
                    ['_id' => $absenceId, 'status' => ['$in' => ['PENDENTE', 'RESPONDIDA']]],
                    ['$set' => ['status' => 'CANCELADA', 'cancelled_at' => $now, 'cancelled_by' => $actor,
                        'cancel_reason' => $reason]],
                    $options
                )->getMatchedCount() !== 1) {
                    throw new DomainException('Esta falta já foi desfeita.');
                }
                [$sessionId, $studentName, $className] = $this->correctAttendance($absence, $actor, $reason, $now, $session);

                $cancelled = 0;
                $toCorrect = [];
                foreach ($this->plan($absenceId, $options) as $item) {
                    $action = $item['action'];
                    if ($action === 'withdraw') {
                        if ($this->withdraw($item, $now, $options)) {
                            $cancelled++;
                            continue;
                        }
                        $action = 'correct';
                    }
                    if ($action !== 'correct') {
                        continue;
                    }
                    $push = $item['push_job'];
                    if ($push !== null && $push['status'] === 'CONCLUIDO' && ($push['reminder_due_at'] ?? null) !== null) {
                        // O push chegou: o lembrete por e-mail desse aviso não deve mais sair.
                        $this->database->push_queue->updateOne(
                            ['_id' => $push['_id'], 'status' => 'CONCLUIDO'],
                            ['$set' => ['reminder_due_at' => null, 'reminder_status' => 'DISPENSADO', 'base_url' => null]],
                            $options
                        );
                    }
                    // Uma correção por responsável, mesmo que tenha recebido push e lembrete.
                    $toCorrect[(string)$item['communication']['guardian_id']] ??= $item['communication'];
                }

                $studentName ??= $this->database->students->findOne(
                    ['_id' => $absence['student_id']], ['projection' => ['name' => 1]] + $options
                )['name'] ?? 'Aluno(a)';
                if ($className === null && ($absence['class_id'] ?? null) instanceof ObjectId) {
                    $className = $this->database->classes->findOne(['_id' => $absence['class_id']], $options)['name'] ?? null;
                }
                foreach ($toCorrect as $original) {
                    $this->queueCorrection($original, $absence, $studentName, $className ?? 'não informada', $now, $options);
                }
                // Sem e-mail, token ou conteúdo da mensagem nos detalhes da auditoria.
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    $actor, 'AUSENCIA_DESFEITA', 'ausencia', $absenceId,
                    'Motivo: ' . $reason . ' | avisos cancelados antes do envio: ' . $cancelled
                        . '; correções: ' . count($toCorrect),
                    $session
                );
                $result = [
                    'absence_id' => (string)$absenceId,
                    'attendance_session_id' => $sessionId === null ? null : (string)$sessionId,
                    'cancelled_notices' => $cancelled,
                    'corrections' => count($toCorrect),
                ];
            });
            if ($result === null) {
                throw new RuntimeException('A transação não produziu o resumo da falta desfeita.');
            }
            return $result;
        } finally {
            $session->endSession();
        }
    }

    /**
     * O que aconteceria se a falta fosse desfeita agora (para a tela de confirmação).
     *
     * @return array{queued: int, corrections: int} avisos que seriam cancelados e responsáveis que receberiam correção
     */
    public function preview(ObjectId $absenceId): array
    {
        $queued = 0;
        $guardians = [];
        foreach ($this->plan($absenceId, []) as $item) {
            if ($item['action'] === 'withdraw') {
                $queued++;
            } elseif ($item['action'] === 'correct') {
                $guardians[(string)$item['communication']['guardian_id']] = true;
            }
        }
        return ['queued' => $queued, 'corrections' => count($guardians)];
    }

    /** Aviso (não a correção) de uma falta desfeita: não pode mais chegar ao responsável. */
    public static function noticeWithdrawn(Database $database, ObjectId $communicationId, ?Session $session = null): bool
    {
        $options = $session === null ? [] : ['session' => $session];
        $communication = $database->communications->findOne(
            ['_id' => $communicationId], ['projection' => ['absence_id' => 1, 'kind' => 1]] + $options
        );
        if ($communication === null || ($communication['kind'] ?? 'AVISO') === 'CORRECAO') {
            return false;
        }
        return $database->absences->countDocuments(
            ['_id' => $communication['absence_id'], 'status' => 'CANCELADA'], $options
        ) > 0;
    }

    private static function normalizeReason(string $reason): string
    {
        $reason = trim(str_replace(["\r\n", "\r"], "\n", $reason));
        if (!mb_check_encoding($reason, 'UTF-8') || mb_strlen($reason) > self::MAX_REASON_LENGTH
            || preg_match('/(?!\n)\p{Cc}/u', $reason) === 1) {
            throw new InvalidArgumentException('Motivo inválido: use no máximo 300 caracteres de texto comum.');
        }
        return $reason === '' ? self::DEFAULT_REASON : $reason;
    }

    /**
     * A entrada do aluno na chamada volta a PRESENTE (a frequência passa a contar presença) e a
     * correção fica registrada na própria chamada.
     *
     * @return array{0: ?ObjectId, 1: ?string, 2: ?string} chamada, nome do aluno e turma gravados na chamada
     */
    private function correctAttendance(
        array $absence, ObjectId $actor, string $reason, UTCDateTime $now, Session $session
    ): array {
        $sessionId = $absence['attendance_session_id'] ?? null;
        $options = ['session' => $session];
        $attendance = $sessionId instanceof ObjectId
            ? $this->database->attendance_sessions->findOne(['_id' => $sessionId], $options) : null;
        if ($attendance === null) {
            return [null, null, null];
        }
        $entries = $attendance['entries'];
        $studentName = null;
        foreach ($entries as $index => $entry) {
            if ((string)$entry['student_id'] !== (string)$absence['student_id']) {
                continue;
            }
            $studentName = $entry['student_name'];
            if ($entry['status'] === 'AUSENTE') {
                $entries[$index]['status'] = 'PRESENTE';
                // O roster_hash lido no filtro garante que a chamada não mudou desde a leitura.
                if ($this->database->attendance_sessions->updateOne(
                    ['_id' => $sessionId, 'roster_hash' => $attendance['roster_hash']],
                    ['$set' => ['entries.' . $index . '.status' => 'PRESENTE',
                        'roster_hash' => MongoAttendance::rosterHash($entries)],
                     '$push' => ['corrections' => [
                         'student_id' => $absence['student_id'], 'absence_id' => $absence['_id'],
                         'from' => 'AUSENTE', 'to' => 'PRESENTE', 'by' => $actor, 'at' => $now, 'reason' => $reason,
                     ]]],
                    $options
                )->getMatchedCount() !== 1) {
                    throw new RuntimeException('A chamada mudou durante a correção; nada foi desfeito. Tente novamente.');
                }
            }
            break;
        }
        return [$sessionId, $studentName, $attendance['class_name'] ?? null];
    }

    /**
     * Destino de cada comunicação da ausência:
     * 'withdraw' — ainda não saiu (fila aguardando, inclusive nova tentativa, ou sem fila): cancela;
     * 'correct' — pode ter chegado (enviada, ou o worker está enviando agora): e-mail de correção;
     * 'none' — nada chegou (falha ou já cancelada) ou já é uma correção.
     *
     * @return list<array{communication: array, action: string, mail_job: ?array, push_job: ?array}>
     */
    private function plan(ObjectId $absenceId, array $options): array
    {
        $items = [];
        foreach ($this->database->communications->find(
            ['absence_id' => $absenceId], ['sort' => ['created_at' => 1, '_id' => 1]] + $options
        ) as $communication) {
            $mailJob = $this->database->mail_queue->findOne(['communication_id' => $communication['_id']], $options);
            $pushJob = $this->database->push_queue->findOne(['communication_id' => $communication['_id']], $options);
            $action = 'none';
            if (($communication['kind'] ?? 'AVISO') === 'CORRECAO') {
                $action = 'none';
            } elseif ($communication['status'] === 'ENVIADO') {
                $action = 'correct';
            } elseif ($communication['status'] === 'PENDENTE') {
                // Quando existe, o e-mail (direto ou de reserva do push) é o envio em curso.
                $job = $mailJob ?? $pushJob;
                $jobStatus = $job === null ? null : $job['status'];
                $action = match (true) {
                    $jobStatus === null, in_array($jobStatus, self::NOT_SENT, true) => 'withdraw',
                    in_array($jobStatus, ['FALHA', 'CANCELADO'], true) => 'none',
                    // PROCESSANDO: o worker exige a comunicação PENDENTE ao concluir; não mexer.
                    default => 'correct',
                };
            }
            $items[] = ['communication' => $communication, 'action' => $action,
                'mail_job' => $mailJob, 'push_job' => $pushJob];
        }
        return $items;
    }

    /** Cancela o aviso que ainda não saiu; false se a fila já o reivindicou (aí ele pode chegar). */
    private function withdraw(array $item, UTCDateTime $now, array $options): bool
    {
        if ($item['mail_job'] !== null) {
            $stopped = $this->database->mail_queue->updateOne(
                ['_id' => $item['mail_job']['_id'], 'status' => ['$in' => self::NOT_SENT]],
                ['$set' => ['status' => 'CANCELADO', 'body' => '', 'started_at' => null,
                    'next_attempt_at' => null, 'processed_at' => $now]],
                $options
            )->getMatchedCount() === 1;
        } elseif ($item['push_job'] !== null) {
            // O conteúdo com link de justificativa, inclusive o e-mail de reserva, sai da fila.
            $stopped = $this->database->push_queue->updateOne(
                ['_id' => $item['push_job']['_id'], 'status' => 'AGUARDANDO'],
                ['$set' => ['status' => 'CANCELADO', 'body' => '', 'url' => '', 'fallback_email' => null,
                    'base_url' => null, 'reminder_due_at' => null, 'started_at' => null, 'next_attempt_at' => null,
                    'processed_at' => $now, 'failure_detail' => 'Falta desfeita antes do envio.']],
                $options
            )->getMatchedCount() === 1;
        } else {
            // Comunicação sem fila (importada): não há envio a interromper.
            $stopped = true;
        }
        if (!$stopped) {
            return false;
        }
        if ($this->database->communications->updateOne(
            ['_id' => $item['communication']['_id'], 'status' => 'PENDENTE'],
            ['$set' => ['status' => 'CANCELADA', 'cancelled_at' => $now, 'failure_detail' => null]], $options
        )->getMatchedCount() !== 1) {
            throw new RuntimeException('Um aviso mudou durante a correção; nada foi desfeito. Tente novamente.');
        }
        return true;
    }

    private function queueCorrection(
        array $original, array $absence, string $studentName, string $className, UTCDateTime $now, array $options
    ): void {
        // Mesmo arquivado, o responsável recebeu o aviso e precisa da correção.
        $guardian = $this->database->guardians->findOne(['_id' => $original['guardian_id']], $options);
        $correctionId = new ObjectId();
        $this->database->communications->insertOne([
            '_id' => $correctionId, 'absence_id' => $absence['_id'], 'guardian_id' => $original['guardian_id'],
            'email_to' => $original['email_to'],
            // Campo obrigatório e único; o token nunca é enviado: a correção não abre formulário.
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'status' => 'PENDENTE', 'created_at' => $now, 'channel' => 'EMAIL',
            'kind' => 'CORRECAO', 'correction_of_id' => $original['_id'],
        ], $options);
        $this->database->mail_queue->insertOne([
            '_id' => new ObjectId(), 'communication_id' => $correctionId,
            'status' => 'AGUARDANDO', 'created_at' => $now,
        ] + AbsenceNotice::correctionEmail(
            (string)$original['email_to'], (string)($guardian['name'] ?? 'responsável'), $studentName, $className,
            $absence['absence_date']
        ), $options);
    }
}
