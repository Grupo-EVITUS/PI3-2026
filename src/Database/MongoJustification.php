<?php
declare(strict_types=1);

namespace Saa\Database;

use DomainException;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Session;
use function MongoDB\with_transaction;

/**
 * O token da comunicação é a única credencial da página do responsável.
 * O link de uma falta desfeita continua abrindo, mas só para dizer que o aviso foi cancelado.
 */
final class MongoJustification
{
    public const CANCELLED_MESSAGE = 'Este aviso foi cancelado pela escola: a falta foi registrada por engano. '
        . 'Não é preciso enviar justificativa.';

    /** Código gravado no banco => rótulo exibido. */
    public const REASONS = [
        'DOENCA' => 'Doença',
        'CONSULTA_MEDICA' => 'Consulta médica',
        'PROBLEMA_FAMILIAR' => 'Problema familiar',
        'TRANSPORTE' => 'Problema de transporte',
        'OUTRO' => 'Outro',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function find(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        return $this->lookup($token);
    }

    private function lookup(string $token, ?Session $session = null): ?array
    {
        $options = $session === null ? [] : ['session' => $session];
        // A correção de falta desfeita não leva link; o filtro por tipo é só uma garantia a mais.
        $communication = $this->database->communications->findOne(
            ['token_hash' => hash('sha256', $token), 'status' => 'ENVIADO', 'kind' => ['$ne' => 'CORRECAO']], $options
        );
        if ($communication === null || (isset($communication['expires_at'])
            && $communication['expires_at'] instanceof UTCDateTime
            && $communication['expires_at']->toDateTime()->getTimestamp() < time())) {
            return null;
        }
        $absence = $this->database->absences->findOne(['_id' => $communication['absence_id']], $options);
        $guardian = $this->database->guardians->findOne(['_id' => $communication['guardian_id']], $options);
        if ($absence === null || $guardian === null) {
            return null;
        }
        $student = $this->database->students->findOne(['_id' => $absence['student_id']], $options);
        if ($student === null) {
            return null;
        }
        $className = null;
        if (($absence['attendance_session_id'] ?? null) !== null) {
            $attendance = $this->database->attendance_sessions->findOne(
                ['_id' => $absence['attendance_session_id']], $options
            );
            $className = $attendance['class_name'] ?? null;
        }
        if ($className === null && ($absence['class_id'] ?? null) !== null) {
            $class = $this->database->classes->findOne(['_id' => $absence['class_id']], $options);
            $className = $class['name'] ?? 'Turma não informada';
        }
        $justification = $this->database->justifications->findOne(['absence_id' => $absence['_id']], $options);
        return [
            'communication_id' => $communication['_id'], 'guardian_id' => $guardian['_id'],
            'absence_id' => $absence['_id'], 'absence_date' => $absence['absence_date'],
            'absence_status' => $absence['status'], 'student_name' => $student['name'],
            'class_name' => $className ?? 'Turma não informada',
            'reason' => $justification['reason'] ?? null,
            'explanation' => $justification['explanation'] ?? null,
            'submitted_at' => isset($justification['submitted_at'])
                ? $justification['submitted_at']->toDateTime()->format('Y-m-d H:i:s') : null,
            'cancelled_at' => ($absence['cancelled_at'] ?? null) instanceof UTCDateTime
                ? $absence['cancelled_at']->toDateTime()->format('Y-m-d H:i:s') : null,
        ];
    }

    public function submit(string $token, string $reason, string $explanation): void
    {
        if (!array_key_exists($reason, self::REASONS)) {
            throw new InvalidArgumentException('Selecione um motivo válido.');
        }
        $explanation = trim($explanation);
        if ($reason === 'OUTRO' && $explanation === '') {
            throw new InvalidArgumentException('Explique o motivo selecionado como Outro.');
        }
        if (mb_strlen($explanation) > 1000 || !mb_check_encoding($explanation, 'UTF-8')
            || str_contains($explanation, "\0")) {
            throw new InvalidArgumentException('Explicação inválida ou acima de 1000 caracteres.');
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            throw new InvalidArgumentException('Este link é inválido ou não está mais disponível.');
        }
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($token, $reason, $explanation): void {
                $absence = $this->lookup($token, $session);
                if ($absence === null) {
                    throw new InvalidArgumentException('Este link é inválido ou não está mais disponível.');
                }
                if ($absence['absence_status'] === 'CANCELADA') {
                    throw new DomainException(self::CANCELLED_MESSAGE);
                }
                if ($absence['absence_status'] !== 'PENDENTE') {
                    throw new DomainException('Esta ausência já foi respondida.');
                }
                $result = $this->database->absences->updateOne(
                    ['_id' => $absence['absence_id'], 'status' => 'PENDENTE'],
                    ['$set' => ['status' => 'RESPONDIDA']], ['session' => $session]
                );
                if ($result->getMatchedCount() !== 1) {
                    throw new DomainException('Esta ausência já foi respondida.');
                }
                $this->database->justifications->insertOne([
                    'absence_id' => $absence['absence_id'], 'communication_id' => $absence['communication_id'],
                    'guardian_id' => $absence['guardian_id'], 'reason' => $reason,
                    'explanation' => $reason === 'OUTRO' ? $explanation : '',
                    'submitted_at' => new UTCDateTime(),
                ], ['session' => $session]);
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    null, 'JUSTIFICATIVA_ENVIADA', 'ausencia', $absence['absence_id'], 'Motivo ' . $reason, $session
                );
            });
        } catch (BulkWriteException $error) {
            if ($error->getCode() !== 11000) {
                throw $error;
            }
            throw new DomainException('Esta ausência já foi respondida.', 0, $error);
        } finally {
            $session->endSession();
        }
    }
}
