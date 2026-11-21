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
 * Consulta das ocorrências de ausência (lista e detalhe) e reenvio de comunicações que falharam.
 * A turma exibida é a da chamada (snapshot), não a turma atual do aluno.
 */
final class MongoOccurrences
{
    public const LIMIT = 200;
    private const UNKNOWN_CLASS = 'Turma não informada';

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Ocorrências mais recentes primeiro, até LIMIT registros.
     *
     * @return list<array{id: string, absence_date: string, status: string, student_name: string,
     *   class_name: string, reason: ?string, explanation: ?string, communication_count: int, failed_count: int}>
     */
    public function list(?string $status = null): array
    {
        if ($status !== null && !in_array($status, ['PENDENTE', 'RESPONDIDA'], true)) {
            throw new InvalidArgumentException('Filtro de status inválido.');
        }
        $absences = $this->database->absences->find(
            $status === null ? [] : ['status' => $status],
            ['sort' => ['absence_date' => -1, '_id' => -1], 'limit' => self::LIMIT]
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
        $counts = [];
        foreach ($this->database->communications->aggregate([
            ['$match' => ['absence_id' => ['$in' => $absenceIds]]],
            ['$group' => [
                '_id' => '$absence_id',
                'total' => ['$sum' => 1],
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
                'failed_count' => (int)($counts[$key]['failed'] ?? 0),
            ];
        }
        return $rows;
    }

    /**
     * @return null|array{id: string, absence_date: string, status: string, created_at: ?string,
     *   student_name: string, class_name: string, grade: ?string, registered_by_name: string,
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
        $user = $this->database->users->findOne(
            ['_id' => $absence['registered_by']], ['projection' => ['name' => 1]]
        );
        $communications = $this->database->communications->find(
            ['absence_id' => $absenceId], ['sort' => ['created_at' => 1, '_id' => 1]]
        )->toArray();
        $guardianIds = [];
        foreach ($communications as $communication) {
            $guardianIds[(string)$communication['guardian_id']] = $communication['guardian_id'];
        }
        $justification = $this->database->justifications->findOne(['absence_id' => $absenceId]);
        if ($justification !== null) {
            $guardianIds[(string)$justification['guardian_id']] = $justification['guardian_id'];
        }
        $guardians = [];
        if ($guardianIds !== []) {
            foreach ($this->database->guardians->find(
                ['_id' => ['$in' => array_values($guardianIds)]], ['projection' => ['name' => 1]]
            ) as $guardian) {
                $guardians[(string)$guardian['_id']] = $guardian['name'];
            }
        }

        $rows = [];
        foreach ($communications as $communication) {
            $rows[] = [
                'id' => (string)$communication['_id'],
                'guardian_name' => $guardians[(string)$communication['guardian_id']] ?? 'Responsável removido',
                'email_to' => $communication['email_to'],
                'status' => $communication['status'],
                'channel' => $communication['channel'] ?? 'EMAIL',
                'failure_detail' => $communication['failure_detail'] ?? null,
                'retried' => isset($communication['retried_at']),
                'is_retry' => isset($communication['retry_of_id']),
            ];
        }
        return [
            'id' => (string)$absenceId,
            'absence_date' => $absence['absence_date'],
            'status' => $absence['status'],
            'created_at' => self::formatDate($absence['created_at'] ?? null),
            'student_name' => $student['name'] ?? 'Aluno removido',
            'class_name' => $class['name'],
            'grade' => $class['grade'],
            'registered_by_name' => $user['name'] ?? 'Usuário removido',
            'communications' => $rows,
            'justification' => $justification === null ? null : [
                'reason' => $justification['reason'],
                'explanation' => $justification['explanation'] ?? '',
                'guardian_name' => $guardians[(string)$justification['guardian_id']] ?? 'Responsável removido',
                'submitted_at' => self::formatDate($justification['submitted_at'] ?? null),
            ],
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
                if ($old['status'] !== 'FALHA') {
                    throw new InvalidArgumentException('Só é possível reenviar uma comunicação que falhou.');
                }
                $absence = $this->database->absences->findOne(['_id' => $old['absence_id']], $options);
                if ($absence === null) {
                    throw new InvalidArgumentException('Ocorrência não encontrada.');
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
