<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeInterface;
use DomainException;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use function MongoDB\with_transaction;

/** Acompanhamento das ausências (lista e detalhe) e reenvio de comunicação que falhou. */
final class MongoOccurrences
{
    public const LIST_LIMIT = 500;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Ausências mais recentes primeiro, com aluno, turma da época, justificativa e totais de comunicação.
     * @return list<array<string, mixed>>
     */
    public function list(string $status = ''): array
    {
        $filter = in_array($status, ['PENDENTE', 'RESPONDIDA'], true) ? ['status' => $status] : [];
        $absences = $this->database->absences->find($filter, [
            'sort' => ['absence_date' => -1, '_id' => -1], 'limit' => self::LIST_LIMIT,
        ])->toArray();
        if ($absences === []) {
            return [];
        }
        $ids = array_map(static fn(array $absence): ObjectId => $absence['_id'], $absences);
        $students = $this->byId('students', array_column($absences, 'student_id'));
        $classNames = $this->classNames($absences);
        $justifications = [];
        foreach ($this->database->justifications->find(['absence_id' => ['$in' => $ids]]) as $justification) {
            $justifications[(string)$justification['absence_id']] = $justification;
        }
        $counts = [];
        foreach ($this->database->communications->aggregate([
            ['$match' => ['absence_id' => ['$in' => $ids]]],
            ['$group' => [
                '_id' => '$absence_id', 'total' => ['$sum' => 1],
                'failed' => ['$sum' => ['$cond' => [['$eq' => ['$status', 'FALHA']], 1, 0]]],
            ]],
        ]) as $row) {
            $counts[(string)$row['_id']] = $row;
        }
        $rows = [];
        foreach ($absences as $absence) {
            $key = (string)$absence['_id'];
            $justification = $justifications[$key] ?? null;
            $rows[] = [
                'id' => $key, 'absence_date' => $absence['absence_date'], 'status' => $absence['status'],
                'student_name' => $students[(string)$absence['student_id']]['name'] ?? 'Aluno não encontrado',
                'class_name' => $classNames[$key],
                'reason' => $justification['reason'] ?? null,
                'explanation' => $justification['explanation'] ?? null,
                'submitted_at' => self::time($justification['submitted_at'] ?? null),
                'communication_count' => (int)($counts[$key]['total'] ?? 0),
                'failed_count' => (int)($counts[$key]['failed'] ?? 0),
            ];
        }
        return $rows;
    }

    /** @return array{absence: array<string, mixed>, communications: list<array<string, mixed>>, justification: ?array<string, mixed>}|null */
    public function detail(ObjectId $absenceId): ?array
    {
        $absence = $this->database->absences->findOne(['_id' => $absenceId]);
        if ($absence === null) {
            return null;
        }
        $student = $this->database->students->findOne(['_id' => $absence['student_id']]);
        $class = ($absence['class_id'] ?? null) === null ? null
            : $this->database->classes->findOne(['_id' => $absence['class_id']]);
        $registeredBy = $this->database->users->findOne(['_id' => $absence['registered_by']]);
        $communications = $this->database->communications->find(
            ['absence_id' => $absenceId], ['sort' => ['created_at' => 1, '_id' => 1]]
        )->toArray();
        $guardianIds = array_column($communications, 'guardian_id');
        $justification = $this->database->justifications->findOne(['absence_id' => $absenceId]);
        if ($justification !== null) {
            $guardianIds[] = $justification['guardian_id'];
        }
        $guardians = $this->byId('guardians', $guardianIds);
        $retried = [];
        foreach ($communications as $communication) {
            if (($communication['retry_of_id'] ?? null) instanceof ObjectId) {
                $retried[(string)$communication['retry_of_id']] = true;
            }
        }
        return [
            'absence' => [
                'id' => (string)$absence['_id'], 'absence_date' => $absence['absence_date'],
                'status' => $absence['status'],
                'student_name' => $student['name'] ?? 'Aluno não encontrado',
                'class_name' => $this->classNames([$absence])[(string)$absence['_id']],
                'grade' => $class['grade'] ?? '',
                'registered_by_name' => $registeredBy['name'] ?? 'Usuário não encontrado',
                'created_at' => self::time($absence['created_at']),
            ],
            'communications' => array_map(static fn(array $communication): array => [
                'id' => (string)$communication['_id'],
                'guardian_name' => $guardians[(string)$communication['guardian_id']]['name'] ?? 'Responsável',
                'email_to' => $communication['email_to'], 'status' => $communication['status'],
                'channel' => $communication['channel'] ?? 'EMAIL',
                'failure_detail' => $communication['failure_detail'] ?? null,
                'is_retry' => ($communication['retry_of_id'] ?? null) !== null,
                'is_reminder' => ($communication['reminder_of_id'] ?? null) !== null,
                'can_retry' => $communication['status'] === 'FALHA' && !isset($communication['retried_at'])
                    && !isset($retried[(string)$communication['_id']]),
            ], $communications),
            'justification' => $justification === null ? null : [
                'reason' => $justification['reason'], 'explanation' => $justification['explanation'],
                'submitted_at' => self::time($justification['submitted_at']),
                'guardian_name' => $guardians[(string)$justification['guardian_id']]['name'] ?? 'Responsável',
            ],
        ];
    }

    /**
     * Gera nova comunicação (novo link) para o mesmo responsável de uma comunicação que falhou.
     * Cada comunicação com falha pode ser reenviada uma única vez; um novo fracasso permite novo reenvio.
     */
    public function retry(ObjectId $actor, ObjectId $communicationId, string $baseUrl): ObjectId
    {
        $baseUrl = MongoAbsenceNotice::baseUrl($baseUrl);
        $session = $this->database->getManager()->startSession();
        try {
            $absenceId = null;
            with_transaction($session, function (Session $session) use (
                $actor, $communicationId, $baseUrl, &$absenceId
            ): void {
                $options = ['session' => $session];
                if ($this->database->users->countDocuments(
                    ['_id' => $actor, 'active' => true, 'profile' => ['$in' => ['AOE', 'COORDENACAO']]], $options
                ) !== 1) {
                    throw new InvalidArgumentException('Reenvio permitido somente à equipe autorizada e ativa.');
                }
                $old = $this->database->communications->findOne(['_id' => $communicationId], $options);
                if ($old === null) {
                    throw new InvalidArgumentException('Comunicação não encontrada.');
                }
                if ($old['status'] !== 'FALHA') {
                    throw new InvalidArgumentException('Só é possível reenviar uma comunicação que falhou.');
                }
                // Marca a original: dois cliques simultâneos disputam o mesmo documento e só um vence.
                $now = new UTCDateTime();
                if ($this->database->communications->updateOne(
                    ['_id' => $communicationId, 'status' => 'FALHA', 'retried_at' => ['$exists' => false]],
                    ['$set' => ['retried_at' => $now]], $options
                )->getMatchedCount() !== 1 || $this->database->communications->countDocuments(
                    ['retry_of_id' => $communicationId], $options
                ) > 0) {
                    throw new DomainException('Esta comunicação já foi reenviada.');
                }
                $absence = $this->database->absences->findOne(['_id' => $old['absence_id']], $options);
                if ($absence === null) {
                    throw new InvalidArgumentException('Ausência não encontrada.');
                }
                if ($absence['status'] !== 'PENDENTE') {
                    throw new DomainException('Esta ausência já foi justificada; não é preciso reenviar.');
                }
                if ($this->database->guardians->updateOne(
                    ['_id' => $old['guardian_id'], 'active' => true], ['$inc' => ['link_revision' => 1]], $options
                )->getMatchedCount() !== 1) {
                    throw new InvalidArgumentException('O responsável foi arquivado; reenvio indisponível.');
                }
                $guardian = $this->database->guardians->findOne(['_id' => $old['guardian_id']], $options);
                $student = $this->database->students->findOne(['_id' => $absence['student_id']], $options);
                $className = $this->classNames([$absence], $session)[(string)$absence['_id']];
                $channel = MongoAbsenceNotice::queue(
                    $this->database, $session, $guardian, $absence['_id'], $student['name'] ?? 'Aluno',
                    $className, $absence['absence_date'], $baseUrl, $now, $communicationId
                );
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    $actor, 'COMUNICACAO_REENVIO_SOLICITADO', 'comunicacao', $communicationId,
                    'Novo envio por ' . ($channel === 'PUSH' ? 'push' : 'e-mail'), $session
                );
                $absenceId = $absence['_id'];
            });
            return $absenceId ?? throw new \RuntimeException('A transação não concluiu o reenvio.');
        } finally {
            $session->endSession();
        }
    }

    /**
     * Turma da época da ausência: nome guardado na chamada; para registros antigos, o nome atual da turma.
     * @param list<array<string, mixed>> $absences
     * @return array<string, string> ID da ausência => nome da turma
     */
    private function classNames(array $absences, ?Session $session = null): array
    {
        $sessionIds = array_values(array_filter(array_column($absences, 'attendance_session_id')));
        $snapshots = $this->byId('attendance_sessions', $sessionIds, $session);
        $classes = $this->byId('classes', array_values(array_filter(array_column($absences, 'class_id'))), $session);
        $names = [];
        foreach ($absences as $absence) {
            $name = $snapshots[(string)($absence['attendance_session_id'] ?? '')]['class_name']
                ?? $classes[(string)($absence['class_id'] ?? '')]['name'] ?? null;
            $names[(string)$absence['_id']] = is_string($name) ? $name : 'Turma não informada';
        }
        return $names;
    }

    /** @return array<string, array<string, mixed>> */
    private function byId(string $collection, array $ids, ?Session $session = null): array
    {
        $ids = array_values(array_filter($ids, static fn(mixed $id): bool => $id instanceof ObjectId));
        if ($ids === []) {
            return [];
        }
        $found = [];
        foreach ($this->database->selectCollection($collection)->find(
            ['_id' => ['$in' => $ids]], $session === null ? [] : ['session' => $session]
        ) as $document) {
            $found[(string)$document['_id']] = $document;
        }
        return $found;
    }

    private static function time(mixed $value): ?string
    {
        if ($value instanceof UTCDateTime) {
            $value = $value->toDateTime();
        }
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }
}
