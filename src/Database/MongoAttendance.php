<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Session;
use RuntimeException;
use function MongoDB\with_transaction;

/**
 * Registra a chamada, as faltas e o aviso aos responsáveis na mesma transação MongoDB.
 * Responsável com aparelho inscrito recebe push (com e-mail de reserva); os demais, e-mail.
 */
final class MongoAttendance
{
    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Impressão digital do roster, na ordem das entradas; recalculada quando uma falta é desfeita.
     *
     * @param list<array{student_id: ObjectId, status: string}> $entries
     */
    public static function rosterHash(array $entries): string
    {
        $hashInput = array_map(static fn(array $entry): array => [
            (string)$entry['student_id'], $entry['status'],
        ], $entries);
        return hash('sha256', json_encode($hashInput, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $studentStatuses IDs hexadecimais de alunos => PRESENTE|AUSENTE
     * @return array{attendance_session_id: string, attendance_counts: array{present: int, absent: int, total: int}, without_guardians: list<string>, notifications: int, push_notifications: int}
     */
    public function record(
        ObjectId $actor, ObjectId $classId, array $studentStatuses, string $attendanceDate, string $baseUrl
    ): array {
        if (!Bson::isDay($attendanceDate)) {
            throw new InvalidArgumentException('Informe uma data válida.');
        }
        $baseUrl = PublicUrl::normalize($baseUrl);
        if ($studentStatuses === [] || count($studentStatuses) > 1000) {
            throw new InvalidArgumentException('Informe a chamada completa de até 1000 alunos.');
        }
        $statuses = [];
        foreach ($studentStatuses as $rawId => $status) {
            $id = Bson::id($rawId);
            if ($id === null || isset($statuses[$id]) || !in_array($status, ['PRESENTE', 'AUSENTE'], true)) {
                throw new InvalidArgumentException('A chamada contém aluno ou situação inválida.');
            }
            $statuses[$id] = $status;
        }

        $session = $this->database->getManager()->startSession();
        try {
            $result = null;
            with_transaction($session, function (Session $session) use (
                $actor, $classId, $statuses, $attendanceDate, $baseUrl, &$result
            ): void {
                $options = ['session' => $session];
                if ($this->database->users->countDocuments(
                    ['_id' => $actor, 'active' => true, 'profile' => ['$in' => ['AOE', 'COORDENACAO']]], $options
                ) !== 1) {
                    throw new RuntimeException('Chamada permitida somente à equipe autorizada e ativa.');
                }
                // O toque disputa com criação de aluno, arquivamento e edição da turma.
                if ($this->database->classes->updateOne(
                    ['_id' => $classId, 'active' => true], ['$inc' => ['link_revision' => 1]], $options
                )->getMatchedCount() !== 1) {
                    throw new InvalidArgumentException('Turma não encontrada ou inativa.');
                }
                $class = $this->database->classes->findOne(['_id' => $classId], $options);
                if ($this->database->attendance_sessions->countDocuments(
                    ['class_id' => $classId, 'attendance_date' => $attendanceDate], $options
                ) > 0) {
                    throw new DomainException('Já existe uma chamada para esta turma e data. Nenhuma alteração foi realizada.');
                }
                $students = $this->database->students->find(
                    ['class_id' => $classId, 'active' => true],
                    ['sort' => ['_id' => 1], 'session' => $session]
                )->toArray();
                if ($students === [] || count($students) > 1000 || count($students) !== count($statuses)) {
                    throw new InvalidArgumentException('Informe a situação de todos os alunos ativos da turma selecionada.');
                }
                $entries = [];
                foreach ($students as $student) {
                    $studentId = $student['_id'];
                    $key = (string)$studentId;
                    if (!isset($statuses[$key])) {
                        throw new InvalidArgumentException('Informe a situação de todos os alunos ativos da turma selecionada.');
                    }
                    // Disputa com transferência/arquivamento do aluno e mudanças de vínculo.
                    if ($this->database->students->updateOne(
                        ['_id' => $studentId, 'class_id' => $classId, 'active' => true],
                        ['$inc' => ['link_revision' => 1]], $options
                    )->getMatchedCount() !== 1) {
                        throw new InvalidArgumentException('A turma mudou durante a chamada. Recarregue a lista.');
                    }
                    $entries[] = [
                        'student_id' => $studentId, 'student_name' => $student['name'],
                        'registration_no' => $student['registration_no'] ?? null, 'status' => $statuses[$key],
                    ];
                }
                $sessionId = new ObjectId();
                $now = new UTCDateTime();
                $hash = self::rosterHash($entries);
                $this->database->attendance_sessions->insertOne([
                    '_id' => $sessionId, 'class_id' => $classId, 'attendance_date' => $attendanceDate,
                    'completed_by' => $actor, 'completed_at' => $now, 'class_name' => $class['name'],
                    'class_shift' => $class['shift'], 'academic_year' => $class['academic_year'],
                    'entries' => $entries, 'roster_hash' => $hash, 'status' => 'CONCLUIDA',
                ], $options);
                $audit = new MongoOperationLogWriter($this->database->operation_logs);
                $audit->append($actor, 'CHAMADA_CONCLUIDA', 'chamada', $sessionId,
                    'Turma ' . (string)$classId . '; data ' . $attendanceDate, $session);
                $absent = 0;
                $notifications = 0;
                $pushNotifications = 0;
                $withoutGuardians = [];
                foreach ($entries as $entry) {
                    if ($entry['status'] !== 'AUSENTE') {
                        continue;
                    }
                    $absent++;
                    $absenceId = new ObjectId();
                    $this->database->absences->insertOne([
                        '_id' => $absenceId, 'student_id' => $entry['student_id'], 'class_id' => $classId,
                        'attendance_session_id' => $sessionId, 'absence_date' => $attendanceDate,
                        'status' => 'PENDENTE', 'registered_by' => $actor, 'created_at' => $now,
                        'class_snapshot_source' => 'captured',
                    ], $options);
                    $audit->append($actor, 'AUSENCIA_REGISTRADA', 'ausencia', $absenceId,
                        'Data ' . $attendanceDate, $session);
                    $linked = [];
                    foreach ($this->database->student_guardians->find(
                        ['student_id' => $entry['student_id']],
                        ['projection' => ['guardian_id' => 1], 'session' => $session]
                    ) as $link) {
                        $linked[(string)$link['guardian_id']] = $link['guardian_id'];
                    }
                    $guardians = $linked === [] ? [] : $this->database->guardians->find(
                        ['_id' => ['$in' => array_values($linked)], 'active' => true],
                        ['sort' => ['_id' => 1], 'session' => $session]
                    )->toArray();
                    if ($guardians === []) {
                        $withoutGuardians[] = $entry['student_name'];
                    }
                    foreach ($guardians as $guardian) {
                        if (!filter_var($guardian['email'], FILTER_VALIDATE_EMAIL)) {
                            throw new InvalidArgumentException('E-mail de responsável inválido; chamada não registrada.');
                        }
                        // Disputa com arquivamento/edição do destinatário durante a transação.
                        if ($this->database->guardians->updateOne(
                            ['_id' => $guardian['_id'], 'active' => true],
                            ['$inc' => ['link_revision' => 1]], $options
                        )->getMatchedCount() !== 1) {
                            throw new InvalidArgumentException('Responsável alterado durante a chamada.');
                        }
                        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                        $communicationId = new ObjectId();
                        $usePush = $this->database->push_subscriptions->countDocuments(
                            ['guardian_id' => $guardian['_id'], 'active' => true], $options
                        ) > 0;
                        $this->database->communications->insertOne([
                            '_id' => $communicationId, 'absence_id' => $absenceId, 'guardian_id' => $guardian['_id'],
                            'email_to' => $guardian['email'], 'token_hash' => hash('sha256', $token),
                            'status' => 'PENDENTE', 'created_at' => $now, 'channel' => $usePush ? 'PUSH' : 'EMAIL',
                        ], $options);
                        $day = (new DateTimeImmutable($attendanceDate))->format('d/m/Y');
                        $path = '/justificar/' . rawurlencode($token);
                        $email = AbsenceNotice::email(
                            $guardian['email'], $guardian['name'], $entry['student_name'], $class['name'],
                            $attendanceDate, $baseUrl . $path
                        );
                        if ($usePush) {
                            // O e-mail fica guardado e só entra na fila se o push não for entregue.
                            $this->database->push_queue->insertOne([
                                '_id' => new ObjectId(), 'communication_id' => $communicationId,
                                'guardian_id' => $guardian['_id'],
                                'title' => 'Ausência de ' . $entry['student_name'],
                                'body' => $entry['student_name'] . ' não compareceu à turma ' . $class['name']
                                    . ' em ' . $day . '. Toque para enviar a justificativa.',
                                'url' => $path, 'fallback_email' => $email,
                                // Endereço público do lembrete por e-mail, caso o push entregue fique sem resposta.
                                'base_url' => $baseUrl,
                                'status' => 'AGUARDANDO', 'created_at' => $now,
                            ], $options);
                            $pushNotifications++;
                        } else {
                            $this->database->mail_queue->insertOne([
                                '_id' => new ObjectId(), 'communication_id' => $communicationId,
                                'status' => 'AGUARDANDO', 'created_at' => $now,
                            ] + $email, $options);
                        }
                        $notifications++;
                    }
                }
                $result = [
                    'attendance_session_id' => (string)$sessionId,
                    'attendance_counts' => ['present' => count($entries) - $absent,
                        'absent' => $absent, 'total' => count($entries)],
                    'without_guardians' => $withoutGuardians, 'notifications' => $notifications,
                    'push_notifications' => $pushNotifications,
                ];
            });
            if ($result === null) {
                throw new RuntimeException('A transação não produziu o resumo da chamada.');
            }
            return $result;
        } catch (BulkWriteException $error) {
            if ($error->getCode() !== 11000) {
                throw $error;
            }
            throw new DomainException('Já existe chamada ou ausência nesta data. Nenhuma alteração foi realizada.', 0, $error);
        } finally {
            $session->endSession();
        }
    }
}
