<?php
declare(strict_types=1);

namespace Saa\Services;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use Saa\Core\Clock;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\JustificationStatus;
use Saa\Models\AttendanceModel;
use Saa\Models\StudentContextModel;
use Saa\Support\Dates;

/**
 * Registro de ausência (issue #29, CONSULTAS 1 e 2 de docs/MODELAGEM_MONGODB.md).
 *
 *   registerAbsence(aluno, 'AAAA-MM-DD', usuário, observação)
 *     1. aluno ativo + responsáveis a avisar (CONSULTA 1, uma agregação);
 *     2. matrícula ativa → turma (nome copiado no registro) e período (CONSULTA 2, uma leitura);
 *     3. regras: data não futura, dentro do período letivo ativo e não anterior ao início da matrícula;
 *     4. grava ABSENT com justification PENDING + status_history; se já havia PRESENT no dia, converte para ABSENT;
 *        se já havia ABSENT/EXCUSED → 409;
 *     5. cria e despacha um e-mail por responsável válido (NotificationService) e devolve um resumo.
 */
final class AttendanceService
{
    public const ACTION_ABSENCE_REGISTERED = 'attendance.absence_registered';
    public const ENTITY = 'attendance_records';
    public const SOURCE = 'INTERNAL_MESSAGE';

    public function __construct(
        private readonly AttendanceModel $attendance,
        private readonly StudentContextModel $context,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{
     *     attendance: array<string, mixed>,
     *     converted_from_present: bool,
     *     student: array{id: string, name: string},
     *     notifications: list<array<string, mixed>>,
     *     warnings: list<string>
     * }
     * @throws ValidationException aluno, data ou observação inválidos; sem matrícula ativa; data fora do período
     * @throws ConflictException já existe ausência do aluno na data
     */
    public function registerAbsence(string $studentId, string $date, string $userId, ?string $note = null): array
    {
        $user = Bson::objectId($userId) ?? throw new InvalidArgumentException('Usuário inválido para registrar a ausência.');
        $data = Validator::make(
            ['student_id' => $studentId, 'date' => $date, 'note' => $note],
            ['student_id' => 'o aluno', 'date' => 'a data da ausência']
        )
            ->required('student_id', 'date')
            ->objectId('student_id')
            ->date('date')
            ->string('note', 500, 0, true)
            ->validate();

        $studentOid = Bson::objectId($data['student_id']);
        $student = $studentOid === null ? null : $this->context->find($studentOid);
        if ($studentOid === null || $student === null) {
            throw new ValidationException(['student_id' => 'Aluno não encontrado ou arquivado.']);
        }
        $ymd = (string)$data['date'];
        if ($ymd > Clock::today()) {
            throw new ValidationException(['date' => 'A data da ausência não pode ser futura.']);
        }
        $enrollment = $this->context->activeEnrollment($studentOid);
        if ($enrollment === null) {
            throw new ValidationException(['student_id' => 'O aluno não tem matrícula ativa em nenhuma turma. Matricule-o antes de registrar a ausência.']);
        }
        $this->assertInsideActivePeriod($ymd, $enrollment);

        $day = Bson::day($ymd);
        $now = Bson::now();
        $noteText = isset($data['note']) && $data['note'] !== '' ? (string)$data['note'] : null;
        $absence = [
            'enrollment_id' => $enrollment['enrollment_id'],
            'class_id' => $enrollment['class_id'],
            'class_name' => mb_substr((string)$enrollment['class_name'], 0, 100),
            'academic_period_id' => $enrollment['academic_period_id'],
            'registered_by' => $user,
            'details' => ['source' => self::SOURCE, 'note' => $noteText],
            'justification' => [
                'status' => JustificationStatus::PENDING->value,
                'guardian_id' => null,
                'reason_code' => null,
                'explanation' => null,
                'submitted_at' => null,
                'notification_id' => null,
                'status_history' => [[
                    'status' => JustificationStatus::PENDING->value,
                    'at' => $now,
                    'by_user_id' => $user,
                    'by_guardian_id' => null,
                ]],
            ],
        ];

        $converted = false;
        $existing = $this->attendance->findByStudentAndDate($studentOid, $day);
        if ($existing !== null) {
            if (($existing['status'] ?? null) !== AttendanceStatus::PRESENT->value) {
                throw new ConflictException(AttendanceModel::DUPLICATE_MESSAGE);
            }
            $record = $this->attendance->convertPresentToAbsent($existing['_id'], $absence + ['updated_at' => $now]);
            if ($record === null) {
                throw new ConflictException(AttendanceModel::DUPLICATE_MESSAGE); // outra pessoa lançou a falta ao mesmo tempo
            }
            $converted = true;
        } else {
            $record = $this->attendance->insert([
                '_id' => new ObjectId(),
                'student_id' => $studentOid,
                'date' => $day,
                'status' => AttendanceStatus::ABSENT->value,
            ] + $absence + ['created_at' => $now]);
        }

        $this->audit->record(self::ACTION_ABSENCE_REGISTERED, self::ENTITY, $record['_id'], [
            'student_id' => (string)$studentOid,
            'date' => $ymd,
            'class_name' => $record['class_name'],
            'converted_from_present' => $converted,
        ]);

        $guardians = array_values(array_filter((array)($student['guardians_to_notify'] ?? []), 'is_array'));
        $result = $this->notifications->notifyAbsence($record, $student, $guardians, $user);

        return [
            'attendance' => $record,
            'converted_from_present' => $converted,
            'student' => ['id' => (string)$studentOid, 'name' => (string)($student['name'] ?? '')],
            'notifications' => $result['notifications'],
            'warnings' => $result['warnings'],
        ];
    }

    /** @param array<string, mixed> $enrollment */
    private function assertInsideActivePeriod(string $ymd, array $enrollment): void
    {
        if ($enrollment['class_name'] === null) {
            throw new ValidationException(['student_id' => 'A turma da matrícula ativa do aluno não foi encontrada. Revise a matrícula antes de registrar a ausência.']);
        }
        $period = $enrollment['period'];
        $start = is_array($period) ? Bson::ymd($period['start_date'] ?? null) : null;
        $end = is_array($period) ? Bson::ymd($period['end_date'] ?? null) : null;
        if (!is_array($period) || ($period['active'] ?? false) !== true || $start === null || $end === null) {
            throw new ValidationException(['date' => 'A matrícula do aluno não pertence ao período letivo ativo.']);
        }
        if ($ymd < $start || $ymd > $end) {
            throw new ValidationException(['date' => sprintf(
                'A data está fora do período letivo ativo (%s a %s).',
                Dates::formatDate($start),
                Dates::formatDate($end)
            )]);
        }
        $enrolledFrom = Bson::ymd($enrollment['start_date'] ?? null);
        if ($enrolledFrom !== null && $ymd < $enrolledFrom) {
            throw new ValidationException(['date' => sprintf(
                'A data é anterior ao início da matrícula do aluno na turma atual (%s).',
                Dates::formatDate($enrolledFrom)
            )]);
        }
    }
}
