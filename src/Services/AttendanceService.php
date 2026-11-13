<?php
declare(strict_types=1);

namespace Saa\Services;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Core\Clock;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
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

    // ------------------------------------------------------------------ consultas (API JSON)

    /**
     * Frequência com filtros (todos opcionais), mais recentes primeiro, paginada.
     *
     * @param array<string, mixed> $input student_id, class_id, date_from, date_to (AAAA-MM-DD), status, justification_status
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     * @throws ValidationException filtro inválido
     */
    public function search(array $input, int $page, int $perPage = RegistrySupport::PER_PAGE): array
    {
        $validator = Validator::make($input, ['date_from' => 'a data inicial', 'date_to' => 'a data final'])
            ->objectId('student_id')
            ->objectId('class_id')
            ->date('date_from')
            ->date('date_to')
            ->in('status', AttendanceStatus::values())
            ->in('justification_status', JustificationStatus::values());
        $valid = $validator->validated();
        if (isset($valid['date_from'], $valid['date_to']) && $valid['date_from'] > $valid['date_to']) {
            $validator->addError('date_to', 'A data final precisa ser igual ou posterior à data inicial.');
        }
        $valid = $validator->validate();

        $filters = [];
        foreach (['student_id', 'class_id'] as $field) {
            $oid = Bson::objectId($valid[$field] ?? null);
            if ($oid !== null) {
                $filters[$field] = $oid;
            }
        }
        foreach (['date_from', 'date_to'] as $field) {
            if (is_string($valid[$field] ?? null) && $valid[$field] !== '') {
                $filters[$field] = Bson::day($valid[$field]);
            }
        }
        foreach (['status', 'justification_status'] as $field) {
            if (is_string($valid[$field] ?? null) && $valid[$field] !== '') {
                $filters[$field] = $valid[$field];
            }
        }

        $page = RegistrySupport::page($page);
        $perPage = max(1, $perPage);
        $result = $this->attendance->search($filters, ($page - 1) * $perPage, $perPage);
        $pagination = RegistrySupport::pagination($page, $result['total'], $perPage);
        if ($pagination['page'] !== $page && $result['total'] > 0) {
            $result = $this->attendance->search($filters, $pagination['skip'], $perPage);
        }
        return ['items' => array_map(self::present(...), $result['items']), 'pagination' => $pagination];
    }

    /**
     * Um registro de frequência com a justificativa e o status_history.
     *
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function get(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Registro de frequência não encontrado.');
        $record = $this->attendance->findWithStudent($oid) ?? throw new NotFoundException('Registro de frequência não encontrado.');
        return self::present($record);
    }

    /**
     * Contexto de notificação do aluno (CONSULTA 1): dados do aluno ATIVO, responsáveis que recebem aviso (ativos e
     * com e-mail VALID) e as 30 faltas mais recentes.
     *
     * @return array{student: array<string, string>, guardians_to_notify: list<array<string, string>>, recent_absences: list<array<string, mixed>>}
     * @throws NotFoundException aluno inexistente ou arquivado
     */
    public function studentContext(string $studentId): array
    {
        $oid = RegistrySupport::idOrFail($studentId, 'Aluno não encontrado ou arquivado.');
        $row = $this->context->find($oid) ?? throw new NotFoundException('Aluno não encontrado ou arquivado.');
        $guardians = [];
        foreach ((array)($row['guardians_to_notify'] ?? []) as $guardian) {
            if (is_array($guardian)) {
                $guardians[] = ['id' => (string)$guardian['_id'], 'name' => (string)($guardian['name'] ?? ''), 'email' => (string)($guardian['email'] ?? '')];
            }
        }
        $absences = [];
        foreach ((array)($row['absences'] ?? []) as $absence) {
            if (!is_array($absence)) {
                continue;
            }
            $justification = is_array($absence['justification'] ?? null) ? $absence['justification'] : [];
            $absences[] = [
                'id' => (string)$absence['_id'],
                'date' => Bson::ymd($absence['date'] ?? null),
                'status' => (string)($absence['status'] ?? ''),
                'class_name' => (string)($absence['class_name'] ?? ''),
                'justification_status' => is_string($justification['status'] ?? null) ? $justification['status'] : null,
                'reason_code' => is_string($justification['reason_code'] ?? null) ? $justification['reason_code'] : null,
                'submitted_at' => self::instant($justification['submitted_at'] ?? null),
            ];
        }
        return [
            'student' => ['id' => (string)$row['_id'], 'name' => (string)($row['name'] ?? ''), 'student_code' => (string)($row['student_code'] ?? '')],
            'guardians_to_notify' => $guardians,
            'recent_absences' => $absences,
        ];
    }

    /**
     * Histórico de comunicações de uma ausência (attendance_id) ou de todas as ausências de um aluno (student_id).
     *
     * @return list<array<string, mixed>>
     * @throws ValidationException sem filtro ou com id malformado
     * @throws NotFoundException ausência inexistente
     */
    public function notificationHistory(?string $attendanceId, ?string $studentId): array
    {
        $attendanceId = $attendanceId === null || trim($attendanceId) === '' ? null : trim($attendanceId);
        $studentId = $studentId === null || trim($studentId) === '' ? null : trim($studentId);
        if ($attendanceId === null && $studentId === null) {
            throw new ValidationException(['attendance_id' => 'Informe a ausência (attendance_id) ou o aluno (student_id).']);
        }
        if ($attendanceId !== null) {
            $oid = Bson::objectId($attendanceId) ?? throw new ValidationException(['attendance_id' => 'Identificador inválido.']);
            $record = $this->attendance->findById($oid) ?? throw new NotFoundException('Registro de frequência não encontrado.');
            if ($studentId !== null && (string)$record['student_id'] !== strtolower($studentId)) {
                return [];
            }
            return $this->notifications->historyOf([$oid]);
        }
        $student = Bson::objectId($studentId) ?? throw new ValidationException(['student_id' => 'Identificador inválido.']);
        return $this->notifications->historyOf($this->attendance->idsOfStudent($student));
    }

    /**
     * Registro de attendance_records → dados públicos (ids em texto, data AAAA-MM-DD, instantes ISO-8601 UTC).
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public static function present(array $record): array
    {
        $id = static fn (mixed $value): ?string => $value instanceof ObjectId ? (string)$value : null;
        $student = is_array($record['student'] ?? null) ? $record['student'] : null;
        $details = is_array($record['details'] ?? null) ? $record['details'] : [];
        $justification = is_array($record['justification'] ?? null) ? $record['justification'] : null;
        $history = [];
        foreach ((array)($justification['status_history'] ?? []) as $item) {
            if (is_array($item)) {
                $history[] = [
                    'status' => (string)($item['status'] ?? ''),
                    'at' => self::instant($item['at'] ?? null),
                    'by_user_id' => $id($item['by_user_id'] ?? null),
                    'by_guardian_id' => $id($item['by_guardian_id'] ?? null),
                ];
            }
        }
        return [
            'id' => (string)$record['_id'],
            'student_id' => $id($record['student_id'] ?? null),
            'student' => $student === null ? null : [
                'id' => (string)$student['_id'],
                'name' => (string)($student['name'] ?? ''),
                'student_code' => (string)($student['student_code'] ?? ''),
            ],
            'date' => Bson::ymd($record['date'] ?? null),
            'status' => (string)($record['status'] ?? ''),
            'class_id' => $id($record['class_id'] ?? null),
            'class_name' => (string)($record['class_name'] ?? ''),
            'academic_period_id' => $id($record['academic_period_id'] ?? null),
            'enrollment_id' => $id($record['enrollment_id'] ?? null),
            'registered_by' => $id($record['registered_by'] ?? null),
            'details' => [
                'source' => is_string($details['source'] ?? null) ? $details['source'] : null,
                'note' => is_string($details['note'] ?? null) ? $details['note'] : null,
            ],
            'justification' => $justification === null ? null : [
                'status' => (string)($justification['status'] ?? ''),
                'guardian_id' => $id($justification['guardian_id'] ?? null),
                'reason_code' => is_string($justification['reason_code'] ?? null) ? $justification['reason_code'] : null,
                'explanation' => is_string($justification['explanation'] ?? null) ? $justification['explanation'] : null,
                'submitted_at' => self::instant($justification['submitted_at'] ?? null),
                'notification_id' => $id($justification['notification_id'] ?? null),
                'status_history' => $history,
            ],
            'created_at' => self::instant($record['created_at'] ?? null),
            'updated_at' => self::instant($record['updated_at'] ?? null),
        ];
    }

    /** UTCDateTime → ISO-8601 em UTC; outros valores → null. */
    private static function instant(mixed $value): ?string
    {
        return $value instanceof UTCDateTime ? (string)Bson::toPlain($value) : null;
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
