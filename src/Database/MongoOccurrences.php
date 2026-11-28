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
 * Consulta das ocorrências de ausência (lista, detalhe e resumo da chamada) e reenvio de
 * comunicações que falharam. A turma exibida é a da chamada (snapshot), não a turma atual do aluno.
 */
final class MongoOccurrences
{
    public const LIMIT = 200;
    public const STATUSES = ['PENDENTE', 'RESPONDIDA', 'CANCELADA'];
    /** Rótulos da situação de cada aviso aos responsáveis. */
    public const NOTICE_LABELS = ['PENDENTE' => 'Na fila', 'ENVIADO' => 'Enviado', 'FALHA' => 'Falha', 'CANCELADA' => 'Cancelado'];
    private const UNKNOWN_CLASS = 'Turma não informada';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Ocorrências mais recentes primeiro, até LIMIT registros. Todos os filtros são opcionais e
     * combinados com E. A turma filtrada é a da chamada (snapshot gravado na ausência) e o
     * período usa o dia da ausência, inclusive nas duas pontas (AAAA-MM-DD).
     *
     * @return list<array{id: string, absence_date: string, status: string, student_name: string,
     *   class_name: string, reason: ?string, explanation: ?string, communication_count: int,
     *   failed_count: int, correction_count: int}>
     */
    public function list(
        ?string $status = null, ?ObjectId $classId = null, ?ObjectId $studentId = null,
        ?string $from = null, ?string $to = null
    ): array {
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Filtro de status inválido.');
        }
        foreach (['inicial' => $from, 'final' => $to] as $label => $day) {
            if ($day !== null && !Bson::isDay($day)) {
                throw new InvalidArgumentException('Data ' . $label . ' inválida.');
            }
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('A data inicial não pode ser posterior à data final.');
        }
        $filter = [];
        if ($status !== null) {
            $filter['status'] = $status;
        }
        if ($classId !== null) {
            $filter['class_id'] = $classId;
        }
        if ($studentId !== null) {
            $filter['student_id'] = $studentId;
        }
        if ($from !== null || $to !== null) {
            $filter['absence_date'] = array_filter(['$gte' => $from, '$lte' => $to], static fn($v) => $v !== null);
        }
        $absences = $this->database->absences->find(
            $filter, ['sort' => ['absence_date' => -1, '_id' => -1], 'limit' => self::LIMIT]
        )->toArray();
        if ($absences === []) {
            return [];
        }
        $absenceIds = [];
        $studentIds = [];
        foreach ($absences as $absence) {
            $absenceIds[] = $absence['_id'];
            $studentIds[(string)$absence['student_id']] = $absence['student_id'];
        }
        $students = [];
        foreach ($this->database->students->find(
            ['_id' => ['$in' => array_values($studentIds)]], ['projection' => ['name' => 1]]
        ) as $student) {
            $students[(string)$student['_id']] = $student['name'];
        }
        $justifications = [];
        foreach ($this->database->justifications->find(['absence_id' => ['$in' => $absenceIds]]) as $row) {
            $justifications[(string)$row['absence_id']] = $row;
        }
        $isCorrection = ['$eq' => ['$kind', 'CORRECAO']];
        $counts = [];
        foreach ($this->database->communications->aggregate([
            ['$match' => ['absence_id' => ['$in' => $absenceIds]]],
            ['$group' => [
                '_id' => '$absence_id',
                // Avisos; as correções de falta desfeita são contadas à parte.
                'total' => ['$sum' => ['$cond' => [$isCorrection, 0, 1]]],
                'corrections' => ['$sum' => ['$cond' => [$isCorrection, 1, 0]]],
                // Falha já reenviada não conta como pendência de ação.
                'failed' => ['$sum' => ['$cond' => [
                    ['$and' => [
                        ['$eq' => ['$status', 'FALHA']],
                        ['$eq' => [['$type' => '$retried_at'], 'missing']],
                    ]], 1, 0,
                ]]],
            ]],
        ]) as $row) {
            $counts[(string)$row['_id']] = $row;
        }
        $classNames = $this->classNames($absences);

        $rows = [];
        foreach ($absences as $absence) {
            $key = (string)$absence['_id'];
            $rows[] = [
                'id' => $key,
                'absence_date' => $absence['absence_date'],
                'status' => $absence['status'],
                'student_name' => $students[(string)$absence['student_id']] ?? 'Aluno removido',
                'class_name' => $classNames[$key]['name'],
                'reason' => $justifications[$key]['reason'] ?? null,
                'explanation' => $justifications[$key]['explanation'] ?? null,
                'communication_count' => (int)($counts[$key]['total'] ?? 0),
                // Falta desfeita não pede reenvio: a falha deixa de ser pendência.
                'failed_count' => $absence['status'] === 'CANCELADA' ? 0 : (int)($counts[$key]['failed'] ?? 0),
                'correction_count' => (int)($counts[$key]['corrections'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * @return null|array{id: string, absence_date: string, status: string, created_at: ?string,
     *   student_name: string, class_name: string, grade: ?string, registered_by_name: string,
     *   attendance_session_id: ?string, cancellation: ?array{cancelled_at: ?string, cancelled_by_name: string, reason: string},
     *   communications: list<array<string, mixed>>, justification: ?array<string, mixed>}
     */
    public function detail(ObjectId $absenceId): ?array
    {
        $absence = $this->database->absences->findOne(['_id' => $absenceId]);
        if ($absence === null) {
            return null;
        }
        $student = $this->database->students->findOne(['_id' => $absence['student_id']]);
        $class = $this->classNames([$absence])[(string)$absenceId];
        $users = $this->userNames([$absence['registered_by'], $absence['cancelled_by'] ?? null]);
        $communications = $this->database->communications->find(
            ['absence_id' => $absenceId], ['sort' => ['created_at' => 1, '_id' => 1]]
        )->toArray();
        $guardianIds = [];
        foreach ($communications as $communication) {
            $guardianIds[] = $communication['guardian_id'];
        }
        $justification = $this->database->justifications->findOne(['absence_id' => $absenceId]);
        if ($justification !== null) {
            $guardianIds[] = $justification['guardian_id'];
        }
        $guardians = $this->guardianNames($guardianIds);

        return [
            'id' => (string)$absenceId,
            'absence_date' => $absence['absence_date'],
            'status' => $absence['status'],
            'created_at' => self::formatDate($absence['created_at'] ?? null),
            'student_name' => $student['name'] ?? 'Aluno removido',
            'class_name' => $class['name'],
            'grade' => $class['grade'],
            'registered_by_name' => $users[(string)$absence['registered_by']] ?? 'Usuário removido',
            'attendance_session_id' => ($absence['attendance_session_id'] ?? null) instanceof ObjectId
                ? (string)$absence['attendance_session_id'] : null,
            'cancellation' => self::cancellation($absence, $users),
            'communications' => array_map(
                static fn(array $communication): array => self::communicationRow($communication, $guardians),
                $communications
            ),
            'justification' => $justification === null ? null : [
                'reason' => $justification['reason'],
                'explanation' => $justification['explanation'] ?? '',
                'guardian_name' => $guardians[(string)$justification['guardian_id']] ?? 'Responsável removido',
                'submitted_at' => self::formatDate($justification['submitted_at'] ?? null),
            ],
        ];
    }

    /**
     * Resumo de uma chamada: contagens atuais (já com as faltas desfeitas) e, para cada aluno
     * marcado como ausente, a situação da falta e de cada aviso aos responsáveis.
     *
     * @return null|array{id: string, class_name: string, class_shift: ?string, attendance_date: string,
     *   completed_by_name: string, completed_at: ?string,
     *   counts: array{present: int, absent: int, total: int, undone: int},
     *   absences: list<array<string, mixed>>, without_guardians: list<string>}
     */
    public function attendanceSummary(ObjectId $sessionId): ?array
    {
        $attendance = $this->database->attendance_sessions->findOne(['_id' => $sessionId]);
        if ($attendance === null) {
            return null;
        }
        $counts = ['present' => 0, 'absent' => 0, 'total' => 0, 'undone' => count($attendance['corrections'] ?? [])];
        $entries = [];
        foreach ($attendance['entries'] as $entry) {
            $counts[$entry['status'] === 'PRESENTE' ? 'present' : 'absent']++;
            $counts['total']++;
            $entries[(string)$entry['student_id']] = $entry;
        }
        $absences = $this->database->absences->find(['attendance_session_id' => $sessionId], ['limit' => 1000])->toArray();
        $absenceIds = array_map(static fn(array $absence): ObjectId => $absence['_id'], $absences);
        $byAbsence = [];
        $guardianIds = [];
        $justifications = [];
        $userIds = [$attendance['completed_by']];
        if ($absenceIds !== []) {
            foreach ($this->database->communications->find(
                ['absence_id' => ['$in' => $absenceIds]], ['sort' => ['created_at' => 1, '_id' => 1]]
            ) as $communication) {
                $byAbsence[(string)$communication['absence_id']][] = $communication;
                $guardianIds[] = $communication['guardian_id'];
            }
            foreach ($this->database->justifications->find(
                ['absence_id' => ['$in' => $absenceIds]], ['projection' => ['absence_id' => 1, 'reason' => 1]]
            ) as $row) {
                $justifications[(string)$row['absence_id']] = $row['reason'];
            }
        }
        foreach ($absences as $absence) {
            $userIds[] = $absence['cancelled_by'] ?? null;
        }
        $guardians = $this->guardianNames($guardianIds);
        $users = $this->userNames($userIds);

        $rows = [];
        $withoutGuardians = [];
        foreach ($absences as $absence) {
            $key = (string)$absence['_id'];
            $entry = $entries[(string)$absence['student_id']] ?? null;
            $name = $entry['student_name'] ?? $this->database->students->findOne(
                ['_id' => $absence['student_id']], ['projection' => ['name' => 1]]
            )['name'] ?? 'Aluno removido';
            $communications = $byAbsence[$key] ?? [];
            // Sem comunicação desde a chamada: não havia responsável ativo naquele momento.
            if ($communications === [] && $absence['status'] !== 'CANCELADA') {
                $withoutGuardians[] = $name;
            }
            $rows[] = [
                'id' => $key,
                'student_name' => $name,
                'registration_no' => $entry['registration_no'] ?? null,
                'status' => $absence['status'],
                'justification_reason' => $justifications[$key] ?? null,
                'cancellation' => self::cancellation($absence, $users),
                'communications' => array_map(
                    static fn(array $communication): array => self::communicationRow($communication, $guardians),
                    $communications
                ),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$a['student_name'], $a['id']] <=> [$b['student_name'], $b['id']]);
        sort($withoutGuardians);

        return [
            'id' => (string)$sessionId,
            'class_name' => $attendance['class_name'] ?? self::UNKNOWN_CLASS,
            'class_shift' => $attendance['class_shift'] ?? null,
            'attendance_date' => $attendance['attendance_date'],
            'completed_by_name' => $users[(string)$attendance['completed_by']] ?? 'Usuário removido',
            'completed_at' => self::formatDate($attendance['completed_at'] ?? null),
            'counts' => $counts,
            'absences' => $rows,
            'without_guardians' => $withoutGuardians,
        ];
    }

    /**
     * Cria uma nova comunicação por e-mail (novo token) para uma que falhou. A anterior fica
     * marcada como reenviada, o que impede reenvios duplicados.
     *
     * @return ObjectId a ocorrência da comunicação reenviada
     */
    public function retry(ObjectId $actor, ObjectId $communicationId, string $baseUrl): ObjectId
    {
        $baseUrl = PublicUrl::normalize($baseUrl);
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
                    throw new RuntimeException('Reenvio permitido somente à equipe autorizada e ativa.');
                }
                $old = $this->database->communications->findOne(['_id' => $communicationId], $options);
                if ($old === null) {
                    throw new InvalidArgumentException('Comunicação não encontrada.');
                }
                $absence = $this->database->absences->findOne(['_id' => $old['absence_id']], $options);
                if ($absence === null) {
                    throw new InvalidArgumentException('Ocorrência não encontrada.');
                }
                if ($absence['status'] === 'CANCELADA') {
                    throw new InvalidArgumentException('Esta falta foi desfeita; não há aviso a reenviar.');
                }
                if ($old['status'] !== 'FALHA') {
                    throw new InvalidArgumentException('Só é possível reenviar uma comunicação que falhou.');
                }
                if ($absence['status'] !== 'PENDENTE') {
                    throw new InvalidArgumentException('A ocorrência já foi respondida; não há o que reenviar.');
                }
                $now = new UTCDateTime();
                if ($this->database->communications->updateOne(
                    ['_id' => $old['_id'], 'status' => 'FALHA', 'retried_at' => ['$exists' => false]],
                    ['$set' => ['retried_at' => $now]], $options
                )->getMatchedCount() !== 1) {
                    throw new DomainException('Esta comunicação já foi reenviada.');
                }
                $guardian = $this->database->guardians->findOne(
                    ['_id' => $old['guardian_id'], 'active' => true], $options
                );
                if ($guardian === null) {
                    throw new InvalidArgumentException('Responsável arquivado ou inexistente; não é possível reenviar.');
                }
                if (!filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('E-mail do responsável inválido; corrija o cadastro e tente de novo.');
                }
                // Disputa com arquivamento/edição do destinatário durante a transação.
                if ($this->database->guardians->updateOne(
                    ['_id' => $guardian['_id'], 'active' => true], ['$inc' => ['link_revision' => 1]], $options
                )->getMatchedCount() !== 1) {
                    throw new InvalidArgumentException('Responsável alterado durante o reenvio.');
                }
                $student = $this->database->students->findOne(['_id' => $absence['student_id']], $options);
                $className = $this->classNames([$absence], $session)[(string)$absence['_id']]['name'];

                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $newId = new ObjectId();
                $this->database->communications->insertOne([
                    '_id' => $newId, 'absence_id' => $absence['_id'], 'guardian_id' => $guardian['_id'],
                    'email_to' => $guardian['email'], 'token_hash' => hash('sha256', $token),
                    'status' => 'PENDENTE', 'created_at' => $now, 'channel' => 'EMAIL',
                    'retry_of_id' => $old['_id'],
                ], $options);
                $this->database->mail_queue->insertOne([
                    '_id' => new ObjectId(), 'communication_id' => $newId,
                    'status' => 'AGUARDANDO', 'created_at' => $now,
                ] + AbsenceNotice::email(
                    $guardian['email'], $guardian['name'], $student['name'] ?? 'Aluno', $className,
                    $absence['absence_date'], $baseUrl . '/justificar/' . rawurlencode($token)
                ), $options);
                // Sem e-mail, token ou conteúdo da mensagem nos detalhes da auditoria.
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    $actor, 'EMAIL_REENVIO_SOLICITADO', 'comunicacao', $newId,
                    'Reenvio da comunicação ' . (string)$old['_id'], $session
                );
                $absenceId = $absence['_id'];
            });
            if (!$absenceId instanceof ObjectId) {
                throw new RuntimeException('A transação não identificou a ocorrência reenviada.');
            }
            return $absenceId;
        } finally {
            $session->endSession();
        }
    }

    /** @param array<string, string> $users nomes indexados pelo ID do usuário */
    private static function cancellation(array $absence, array $users): ?array
    {
        if ($absence['status'] !== 'CANCELADA') {
            return null;
        }
        $by = $absence['cancelled_by'] ?? null;
        return [
            'cancelled_at' => self::formatDate($absence['cancelled_at'] ?? null),
            'cancelled_by_name' => $by === null ? 'Usuário removido' : ($users[(string)$by] ?? 'Usuário removido'),
            'reason' => $absence['cancel_reason'] ?? MongoAbsenceCancellation::DEFAULT_REASON,
        ];
    }

    /** @param array<string, string> $guardians nomes indexados pelo ID do responsável */
    private static function communicationRow(array $communication, array $guardians): array
    {
        return [
            'id' => (string)$communication['_id'],
            'guardian_name' => $guardians[(string)$communication['guardian_id']] ?? 'Responsável removido',
            'email_to' => $communication['email_to'],
            'status' => $communication['status'],
            'channel' => $communication['channel'] ?? 'EMAIL',
            'failure_detail' => $communication['failure_detail'] ?? null,
            'retried' => isset($communication['retried_at']),
            'is_retry' => isset($communication['retry_of_id']),
            'is_reminder' => isset($communication['reminder_of_id']),
            'is_correction' => ($communication['kind'] ?? 'AVISO') === 'CORRECAO',
            'cancelled_at' => self::formatDate($communication['cancelled_at'] ?? null),
        ];
    }

    /**
     * @param list<ObjectId> $ids
     * @return array<string, string> nome de cada responsável (inclusive arquivados)
     */
    private function guardianNames(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $unique[(string)$id] = $id;
        }
        $names = [];
        if ($unique !== []) {
            foreach ($this->database->guardians->find(
                ['_id' => ['$in' => array_values($unique)]], ['projection' => ['name' => 1]]
            ) as $guardian) {
                $names[(string)$guardian['_id']] = $guardian['name'];
            }
        }
        return $names;
    }

    /**
     * @param list<?ObjectId> $ids
     * @return array<string, string> nome de cada usuário da equipe
     */
    private function userNames(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            if ($id instanceof ObjectId) {
                $unique[(string)$id] = $id;
            }
        }
        $names = [];
        if ($unique !== []) {
            foreach ($this->database->users->find(
                ['_id' => ['$in' => array_values($unique)]], ['projection' => ['name' => 1]]
            ) as $user) {
                $names[(string)$user['_id']] = $user['name'];
            }
        }
        return $names;
    }

    /**
     * Turma de cada ausência: o snapshot da chamada quando existe; senão a turma gravada na ausência.
     *
     * @param list<array|object> $absences
     * @return array<string, array{name: string, grade: ?string}> indexado pelo ID da ausência
     */
    private function classNames(array $absences, ?Session $session = null): array
    {
        $options = $session === null ? [] : ['session' => $session];
        $sessionIds = [];
        $classIds = [];
        foreach ($absences as $absence) {
            if (($absence['attendance_session_id'] ?? null) !== null) {
                $sessionIds[(string)$absence['attendance_session_id']] = $absence['attendance_session_id'];
            }
            if (($absence['class_id'] ?? null) !== null) {
                $classIds[(string)$absence['class_id']] = $absence['class_id'];
            }
        }
        $snapshots = [];
        if ($sessionIds !== []) {
            foreach ($this->database->attendance_sessions->find(
                ['_id' => ['$in' => array_values($sessionIds)]], ['projection' => ['class_name' => 1]] + $options
            ) as $row) {
                $snapshots[(string)$row['_id']] = $row['class_name'];
            }
        }
        $classes = [];
        if ($classIds !== []) {
            foreach ($this->database->classes->find(
                ['_id' => ['$in' => array_values($classIds)]], ['projection' => ['name' => 1, 'grade' => 1]] + $options
            ) as $row) {
                $classes[(string)$row['_id']] = $row;
            }
        }
        $result = [];
        foreach ($absences as $absence) {
            $class = ($absence['class_id'] ?? null) === null ? null : ($classes[(string)$absence['class_id']] ?? null);
            $snapshot = ($absence['attendance_session_id'] ?? null) === null
                ? null : ($snapshots[(string)$absence['attendance_session_id']] ?? null);
            $result[(string)$absence['_id']] = [
                'name' => $snapshot ?? $class['name'] ?? self::UNKNOWN_CLASS,
                'grade' => $class['grade'] ?? null,
            ];
        }
        return $result;
    }

    private static function formatDate(mixed $value): ?string
    {
        return $value instanceof UTCDateTime ? $value->toDateTime()->format('Y-m-d H:i:s') : null;
    }
}
