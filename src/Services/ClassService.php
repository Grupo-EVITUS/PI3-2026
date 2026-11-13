<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Domain\Shift;
use Saa\Models\AcademicPeriodModel;
use Saa\Models\EnrollmentModel;
use Saa\Models\SchoolClassModel;
use Saa\Models\StudentModel;

/**
 * Turmas (issue #40): cadastro por período letivo (código único no período), edição, ficha com os alunos
 * matriculados, associação de alunos (via EnrollmentService) e arquivamento — recusado enquanto houver
 * matrículas ativas. Todas as escritas são auditadas.
 */
final class ClassService
{
    public const ARCHIVE_BLOCKED = 'Transfira os alunos antes de arquivar a turma.';
    /** Código repetido no mesmo período (a API JSON responde com 409). */
    public const DUPLICATE_CODE = 'Já existe uma turma com este código neste período letivo.';

    private const LABELS = [
        'academic_period_id' => 'o período letivo',
        'code' => 'o código da turma',
        'name' => 'o nome da turma',
        'grade' => 'a série ou ano',
        'shift' => 'o turno',
    ];
    private const PICKER_LIMIT = 15;

    public function __construct(
        private readonly SchoolClassModel $classes,
        private readonly AcademicPeriodModel $periods,
        private readonly EnrollmentModel $enrollments,
        private readonly StudentModel $students,
        private readonly EnrollmentService $enrollmentService,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Lista de turmas com filtros. $period: null = período ativo (ou todos, se não houver), "all" = todos, ou um id.
     *
     * @return array{items: list<array<string, mixed>>, filters: array{period: string, shift: string, status: string}}
     */
    public function list(?string $period, ?string $shift, ?string $status): array
    {
        $periodId = null;
        $periodFilter = 'all';
        if ($period === null || $period === '') {
            $active = $this->periods->findActive();
            if ($active !== null) {
                $periodId = $active['_id'];
                $periodFilter = (string)$periodId;
            }
        } elseif ($period !== 'all') {
            $periodId = Bson::objectId($period);
            $periodFilter = $periodId !== null ? (string)$periodId : 'all';
        }
        $shiftFilter = Shift::tryFromValue($shift)?->value;
        $active = RegistrySupport::activeFilter($status);

        $items = array_map(self::presentRow(...), $this->classes->listWithCounts($periodId, $shiftFilter, $active));
        return [
            'items' => $items,
            'filters' => ['period' => $periodFilter, 'shift' => $shiftFilter ?? '', 'status' => $active ? 'active' : 'archived'],
        ];
    }

    /**
     * Ficha da turma: dados, período, alunos com matrícula ativa e (opcional) alunos encontrados para associar.
     *
     * @return array{class: array<string, mixed>, students: list<array<string, mixed>>, candidates: list<array<string, mixed>>|null}
     * @throws NotFoundException
     */
    public function show(string $id, ?string $studentTerm = null): array
    {
        $class = $this->find($id);
        $period = $this->periods->findById($class['academic_period_id']);
        $students = [];
        foreach ($this->enrollments->activeStudentsOfClass($class['_id']) as $row) {
            $student = $row['student'];
            $students[] = [
                'id' => (string)$student['_id'],
                'name' => (string)$student['name'],
                'student_code' => (string)$student['student_code'],
                'birth_date' => Bson::ymd($student['birth_date'] ?? null),
                'since' => Bson::ymd($row['start_date'] ?? null),
                'guardian_count' => is_array($student['guardians'] ?? null) ? count($student['guardians']) : 0,
            ];
        }

        $candidates = null;
        if ($studentTerm !== null && trim($studentTerm) !== '') {
            $candidates = [];
            foreach ($this->students->pickable(Bson::contains($studentTerm), $class['_id'], self::PICKER_LIMIT) as $student) {
                $candidates[] = [
                    'id' => (string)$student['_id'],
                    'name' => (string)$student['name'],
                    'student_code' => (string)$student['student_code'],
                    'current_class' => isset($student['class']['code']) ? (string)$student['class']['code'] : null,
                ];
            }
        }

        $data = self::presentRow($class + [
            'period_name' => $period['name'] ?? null,
            'period_active' => ($period['active'] ?? false) === true,
            'student_count' => count($students),
        ]);
        return ['class' => $data, 'students' => $students, 'candidates' => $candidates];
    }

    /**
     * @param array<string, mixed> $input academic_period_id, code, name, grade, shift
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function create(array $input): array
    {
        $validator = $this->validator($input, true);
        $data = $validator->validated();
        if (isset($data['academic_period_id']) && $this->periods->findById($data['academic_period_id']) === null) {
            $validator->addError('academic_period_id', 'Selecione um período letivo válido.');
        }
        $data = $validator->validate();
        $now = Bson::now();
        try {
            $class = $this->classes->insert([
                'academic_period_id' => new ObjectId($data['academic_period_id']),
                'code' => self::code($data['code']),
                'name' => $data['name'],
                'grade' => $data['grade'],
                'shift' => $data['shift'],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (ConflictException) {
            throw new ValidationException(['code' => self::DUPLICATE_CODE]);
        }
        $this->audit->record('class.create', 'classes', $class['_id'], ['code' => $class['code'], 'academic_period_id' => (string)$class['academic_period_id']]);
        return self::presentRow($class);
    }

    /**
     * Edita código, nome, série e turno (o período não muda: as matrículas dependem dele).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(string $id, array $input): array
    {
        $class = $this->find($id);
        $data = $this->validator($input, false)->validate();
        $fields = ['code' => self::code($data['code']), 'name' => $data['name'], 'grade' => $data['grade'], 'shift' => $data['shift']];
        try {
            $this->classes->update($class['_id'], $fields);
        } catch (ConflictException) {
            throw new ValidationException(['code' => self::DUPLICATE_CODE]);
        }
        $this->audit->record('class.update', 'classes', $class['_id'], ['code' => $fields['code']]);
        return self::presentRow($this->find($id));
    }

    /**
     * Arquiva a turma (active=false). Recusa se ainda houver alunos com matrícula ativa nela.
     *
     * @throws NotFoundException
     * @throws ValidationException turma com alunos
     */
    public function archive(string $id): void
    {
        $class = $this->find($id);
        if ($this->enrollments->countActiveOfClass($class['_id']) > 0) {
            throw new ValidationException(['class' => self::ARCHIVE_BLOCKED], self::ARCHIVE_BLOCKED);
        }
        if (($class['active'] ?? false) === true) {
            $this->classes->setActive($class['_id'], false);
            $this->audit->record('class.archive', 'classes', $class['_id'], ['code' => $class['code']]);
        }
    }

    /**
     * Associa um aluno à turma (matrícula; troca de turma se ele já estiver em outra).
     *
     * @return array{student_name: string, changed: bool}
     * @throws NotFoundException turma inexistente
     * @throws ValidationException aluno ou turma inválidos
     */
    public function enrollStudent(string $classId, ?string $studentId): array
    {
        $class = $this->find($classId);
        $studentOid = Bson::objectId($studentId);
        $student = $studentOid === null ? null : $this->students->findById($studentOid);
        if ($student === null) {
            throw new ValidationException(['student_id' => 'Selecione um aluno.']);
        }
        $result = $this->enrollmentService->assign($student['_id'], (string)$class['_id'], 'student_id');
        return ['student_name' => (string)$student['name'], 'changed' => $result['changed']];
    }

    /**
     * Documento (com campos agregados opcionais) → dados de exibição.
     *
     * @param array<string, mixed> $class
     * @return array<string, mixed>
     */
    public static function presentRow(array $class): array
    {
        return [
            'id' => (string)$class['_id'],
            'academic_period_id' => (string)($class['academic_period_id'] ?? ''),
            'period_name' => isset($class['period_name']) ? (string)$class['period_name'] : null,
            'period_active' => ($class['period_active'] ?? false) === true,
            'code' => (string)($class['code'] ?? ''),
            'name' => (string)($class['name'] ?? ''),
            'grade' => (string)($class['grade'] ?? ''),
            'shift' => (string)($class['shift'] ?? ''),
            'active' => ($class['active'] ?? false) === true,
            'student_count' => (int)($class['student_count'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Turma não encontrada.');
        return $this->classes->findById($oid) ?? throw new NotFoundException('Turma não encontrada.');
    }

    /** @param array<string, mixed> $input */
    private function validator(array $input, bool $withPeriod): Validator
    {
        $required = ['code', 'name', 'grade', 'shift'];
        if ($withPeriod) {
            array_unshift($required, 'academic_period_id');
        }
        $validator = Validator::make($input, self::LABELS)
            ->required(...$required)
            ->string('code', 20, 1)
            ->string('name', 100, 1)
            ->string('grade', 50, 1)
            ->in('shift', Shift::values());
        if ($withPeriod) {
            $validator->objectId('academic_period_id');
        }
        $code = $validator->validated()['code'] ?? null;
        if (is_string($code) && preg_match('/^[\p{L}\p{N} ._\-º°ª]+$/u', $code) !== 1) {
            $validator->addError('code', 'Use letras, números, espaço, ponto ou hífen no código.');
        }
        return $validator;
    }

    /** Código em maiúsculas e sem espaços duplicados ("6a" e "6A" são a mesma turma). */
    private static function code(string $code): string
    {
        return mb_strtoupper((string)preg_replace('/\s+/u', ' ', trim($code)), 'UTF-8');
    }
}
