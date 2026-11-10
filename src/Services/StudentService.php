<?php
declare(strict_types=1);

namespace Saa\Services;

use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Domain\EndReason;
use Saa\Models\StudentModel;

/**
 * Alunos (issue #38): cadastro (nome, matrícula escolar única, nascimento no passado e turma do período ativo, que
 * gera a matrícula), edição, arquivamento/reativação, listagem paginada com busca e ficha completa (turma atual,
 * responsáveis e histórico de matrículas). Vínculos com responsáveis: GuardianLinkService; turmas: EnrollmentService.
 */
final class StudentService
{
    private const LABELS = [
        'name' => 'o nome do aluno',
        'student_code' => 'a matrícula escolar',
        'birth_date' => 'a data de nascimento',
        'class_id' => 'a turma',
    ];
    private const DUPLICATE_CODE = 'Já existe um aluno com esta matrícula escolar.';

    public function __construct(
        private readonly StudentModel $students,
        private readonly EnrollmentService $enrollments,
        private readonly GuardianLinkService $links,
        private readonly GuardianService $guardians,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Listagem paginada (20 por página) com busca por nome/matrícula, filtro por turma e situação.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, filters: array{q: string, class: string, status: string}}
     */
    public function list(?string $term, ?string $classId, ?string $status, int $page): array
    {
        $term = trim((string)$term);
        $regex = $term !== '' ? Bson::contains($term) : null;
        $class = Bson::objectId($classId);
        $active = RegistrySupport::activeFilter($status);
        $page = RegistrySupport::page($page);
        $result = $this->students->search($regex, $class, $active, ($page - 1) * RegistrySupport::PER_PAGE, RegistrySupport::PER_PAGE);
        $pagination = RegistrySupport::pagination($page, $result['total']);
        if ($pagination['page'] !== $page && $result['total'] > 0) {
            $result = $this->students->search($regex, $class, $active, $pagination['skip'], RegistrySupport::PER_PAGE);
        }
        return [
            'items' => array_map(self::present(...), $result['items']),
            'pagination' => $pagination,
            'filters' => ['q' => mb_substr($term, 0, 100), 'class' => $class !== null ? (string)$class : '', 'status' => $active ? 'active' : 'archived'],
        ];
    }

    /**
     * Ficha do aluno: dados, turma atual, responsáveis vinculados, histórico de matrículas e, se houver busca,
     * os responsáveis encontrados para vincular.
     *
     * @return array{student: array<string, mixed>, guardians: list<array<string, mixed>>, history: list<array<string, mixed>>, candidates: list<array<string, mixed>>|null}
     * @throws NotFoundException
     */
    public function show(string $id, ?string $guardianTerm = null): array
    {
        $student = $this->find($id);
        $history = $this->enrollments->history($student['_id']);
        $current = null;
        foreach ($history as $item) {
            if ($item['active']) {
                $current = $item;
                break;
            }
        }
        $data = self::present($student);
        $data['class'] = $current === null ? null : [
            'id' => $current['class_id'],
            'code' => $current['class_code'],
            'name' => $current['class_name'],
            'period_name' => $current['period_name'],
            'since' => $current['start_date'],
        ];

        $candidates = null;
        if ($guardianTerm !== null && trim($guardianTerm) !== '') {
            $linked = array_map(static fn (array $link): mixed => $link['guardian_id'], array_filter((array)($student['guardians'] ?? []), 'is_array'));
            $candidates = $this->guardians->pickable($guardianTerm, array_values($linked));
        }

        return [
            'student' => $data,
            'guardians' => $this->links->linkedGuardians($student),
            'history' => $history,
            'candidates' => $candidates,
        ];
    }

    /**
     * Cadastra o aluno e o matricula na turma escolhida (do período letivo ativo).
     *
     * @param array<string, mixed> $input name, student_code, birth_date, class_id
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function create(array $input): array
    {
        $validator = $this->validator($input)->required('class_id');
        if (!isset($validator->errors()['class_id'])) {
            $classError = $this->enrollments->classError(is_string($input['class_id'] ?? null) ? trim($input['class_id']) : null);
            if ($classError !== null) {
                $validator->addError('class_id', $classError);
            }
        }
        $data = $validator->validate();
        $now = Bson::now();
        try {
            $student = $this->students->insert([
                'name' => $data['name'],
                'student_code' => RegistrySupport::studentCode($data['student_code']),
                'birth_date' => Bson::day($data['birth_date']),
                'guardians' => [],
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (ConflictException) {
            throw new ValidationException(['student_code' => self::DUPLICATE_CODE]);
        }
        $this->audit->record('student.create', 'students', $student['_id'], ['student_code' => $student['student_code']]);
        $this->enrollments->assign($student['_id'], (string)$input['class_id']);
        return self::present($student);
    }

    /**
     * Edita nome, matrícula escolar e data de nascimento (a turma muda por changeClass()).
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(string $id, array $input): array
    {
        $student = $this->find($id);
        $data = $this->validator($input)->validate();
        $fields = [
            'name' => $data['name'],
            'student_code' => RegistrySupport::studentCode($data['student_code']),
            'birth_date' => Bson::day($data['birth_date']),
        ];
        try {
            $this->students->update($student['_id'], $fields);
        } catch (ConflictException) {
            throw new ValidationException(['student_code' => self::DUPLICATE_CODE]);
        }
        $this->audit->record('student.update', 'students', $student['_id'], ['student_code' => $fields['student_code']]);
        return self::present($this->find($id));
    }

    /**
     * Arquiva o aluno (active=false) e encerra a matrícula ativa com TRANSFER — nada é apagado.
     *
     * @throws NotFoundException
     */
    public function archive(string $id): void
    {
        $student = $this->find($id);
        if (($student['active'] ?? false) !== true) {
            return;
        }
        $this->students->setActive($student['_id'], false);
        $ended = $this->enrollments->endActive($student['_id'], EndReason::TRANSFER);
        $this->audit->record('student.archive', 'students', $student['_id'], ['enrollment_ended' => $ended]);
    }

    /** @throws NotFoundException */
    public function reactivate(string $id): void
    {
        $student = $this->find($id);
        if (($student['active'] ?? false) === true) {
            return;
        }
        $this->students->setActive($student['_id'], true);
        $this->audit->record('student.reactivate', 'students', $student['_id']);
    }

    /**
     * Troca de turma (ou primeira matrícula, se o aluno estiver sem turma).
     *
     * @return bool true se foi troca (a matrícula anterior foi encerrada)
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function changeClass(string $id, ?string $classId): bool
    {
        $student = $this->find($id);
        return $this->enrollments->assign($student['_id'], $classId)['changed'];
    }

    /**
     * @param array<string, mixed> $student documento (com "class" e "guardian_count" quando vem da busca)
     * @return array<string, mixed>
     */
    public static function present(array $student): array
    {
        $class = is_array($student['class'] ?? null) ? $student['class'] : null;
        return [
            'id' => (string)$student['_id'],
            'name' => (string)($student['name'] ?? ''),
            'student_code' => (string)($student['student_code'] ?? ''),
            'birth_date' => Bson::ymd($student['birth_date'] ?? null),
            'active' => ($student['active'] ?? false) === true,
            'class' => $class === null ? null : [
                'id' => (string)$class['_id'],
                'code' => (string)($class['code'] ?? ''),
                'name' => (string)($class['name'] ?? ''),
            ],
            'guardian_count' => (int)($student['guardian_count'] ?? count((array)($student['guardians'] ?? []))),
        ];
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Aluno não encontrado.');
        return $this->students->findById($oid) ?? throw new NotFoundException('Aluno não encontrado.');
    }

    /** @param array<string, mixed> $input */
    private function validator(array $input): Validator
    {
        $validator = Validator::make($input, self::LABELS)
            ->required('name', 'student_code', 'birth_date')
            ->string('name', 120, 2)
            ->string('student_code', 30, 1)
            ->date('birth_date');
        $birth = $validator->validated()['birth_date'] ?? null;
        if (is_string($birth)) {
            if ($birth >= RegistrySupport::today()) {
                $validator->addError('birth_date', 'A data de nascimento precisa ser anterior a hoje.');
            } elseif ($birth < '1900-01-01') {
                $validator->addError('birth_date', 'Informe uma data de nascimento válida.');
            }
        }
        return $validator;
    }
}
