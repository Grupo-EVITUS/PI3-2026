<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Database\Schema;
use Saa\Domain\Relationship;
use Saa\Models\GuardianModel;
use Saa\Models\StudentModel;

/**
 * Vínculos aluno ↔ responsável (issues #38 e #39, MD-04), embutidos em students.guardians:
 *  - no máximo 3 vínculos por aluno, sem repetir o responsável;
 *  - no máximo um principal: o primeiro vínculo vira principal automaticamente e marcar outro como principal
 *    desmarca o anterior; ao remover o principal, o próximo vínculo assume;
 *  - receives_absence_notifications é true por padrão.
 * Gravação com controle otimista (StudentModel::replaceGuardians): alterações simultâneas viram 409, nunca perda de dados.
 */
final class GuardianLinkService
{
    public const MAX_LINKS = Schema::MAX_GUARDIANS_PER_STUDENT;
    public const LIMIT_MESSAGE = 'O aluno já tem 3 responsáveis vinculados. Remova um vínculo antes de adicionar outro.';
    private const CHANGED_MESSAGE = 'O cadastro do aluno foi alterado por outra pessoa. Recarregue a página e tente novamente.';

    public function __construct(
        private readonly StudentModel $students,
        private readonly GuardianModel $guardians,
        private readonly GuardianService $guardianService,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Vincula um responsável já cadastrado.
     *
     * @param array<string, mixed> $input guardian_id, relationship, is_primary (bool|null), receives_absence_notifications (bool|null)
     * @return array<string, mixed> responsável vinculado (dados de exibição)
     * @throws NotFoundException aluno inexistente
     * @throws ValidationException
     */
    public function linkExisting(string $studentId, array $input): array
    {
        $student = $this->findStudent($studentId);
        $validator = self::linkValidator($input)->required('guardian_id')->objectId('guardian_id');
        $data = $validator->validated();
        $guardian = isset($data['guardian_id']) ? $this->guardians->findById($data['guardian_id']) : null;
        if (isset($data['guardian_id']) && $guardian === null) {
            $validator->addError('guardian_id', 'Selecione um responsável válido.');
        } elseif ($guardian !== null && ($guardian['active'] ?? false) !== true) {
            $validator->addError('guardian_id', 'Este responsável está arquivado. Reative o cadastro dele antes de vincular.');
        } elseif ($guardian !== null && self::position($student, $guardian['_id']) !== null) {
            $validator->addError('guardian_id', 'Este responsável já está vinculado ao aluno.');
        }
        self::assertRoom($student, $validator);
        $data = $validator->validate();

        $this->append($student, $guardian, $data['relationship'], $input);
        return GuardianService::present($guardian);
    }

    /**
     * Cadastra um responsável novo (guardian_name, guardian_email, guardian_phone) e já o vincula ao aluno.
     *
     * @param array<string, mixed> $input guardian_name, guardian_email, guardian_phone, relationship, is_primary, receives_absence_notifications
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function linkNew(string $studentId, array $input): array
    {
        $student = $this->findStudent($studentId);
        $validator = $this->guardianService->contactValidator($input, 'guardian_');
        $link = self::linkValidator($input);
        foreach ($link->errors() as $field => $message) {
            $validator->addError($field, $message);
        }
        self::assertRoom($student, $validator);
        $data = $validator->validate();
        $relationship = (string)$link->validated()['relationship'];

        $guardian = $this->guardianService->insertValidated($data['guardian_name'], $data['guardian_email'], $data['guardian_phone'], 'guardian_');
        $this->append($student, $guardian, $relationship, $input);
        return GuardianService::present($guardian);
    }

    /**
     * Cadastro pela tela de responsáveis: contato (name, email, phone) e, opcionalmente, o aluno a vincular,
     * informado pela matrícula escolar (student_code + relationship). Tudo é validado antes de gravar.
     *
     * @param array<string, mixed> $input
     * @return array{guardian: array<string, mixed>, student_name: string|null}
     * @throws ValidationException
     */
    public function createGuardian(array $input): array
    {
        $validator = $this->guardianService->contactValidator($input)->string('student_code', 30);
        $student = null;
        $code = $validator->validated()['student_code'] ?? null;
        if (is_string($code) && $code !== '') {
            $student = $this->students->findByCode(RegistrySupport::studentCode($code));
            if ($student === null || ($student['active'] ?? false) !== true) {
                $validator->addError('student_code', 'Nenhum aluno ativo com esta matrícula escolar.');
                $student = null;
            }
            $link = self::linkValidator($input);
            foreach ($link->errors() as $field => $message) {
                $validator->addError($field, $message);
            }
            if ($student !== null && count(self::links($student)) >= self::MAX_LINKS) {
                $validator->addError('student_code', self::LIMIT_MESSAGE);
            }
        }
        $data = $validator->validate();

        $guardian = $this->guardianService->insertValidated($data['name'], $data['email'], $data['phone']);
        if ($student !== null) {
            $this->append($student, $guardian, trim((string)$input['relationship']), $input);
        }
        return ['guardian' => GuardianService::present($guardian), 'student_name' => $student !== null ? (string)$student['name'] : null];
    }

    /**
     * Altera parentesco, principal e "recebe avisos" de um vínculo.
     *
     * @param array<string, mixed> $input relationship, is_primary (bool), receives_absence_notifications (bool)
     * @throws NotFoundException aluno inexistente ou responsável não vinculado
     * @throws ValidationException
     */
    public function updateLink(string $studentId, string $guardianId, array $input): void
    {
        $student = $this->findStudent($studentId);
        $gid = Bson::objectId($guardianId);
        $position = $gid === null ? null : self::position($student, $gid);
        if ($position === null) {
            throw new NotFoundException('Este responsável não está vinculado ao aluno.');
        }
        $data = self::linkValidator($input)->validate();
        $links = self::links($student);
        $primary = ($input['is_primary'] ?? null) === true;
        $notify = ($input['receives_absence_notifications'] ?? null) !== false;
        foreach ($links as $index => $link) {
            if ($index === $position) {
                $links[$index]['relationship'] = $data['relationship'];
                $links[$index]['is_primary'] = $primary;
                $links[$index]['receives_absence_notifications'] = $notify;
            } elseif ($primary) {
                $links[$index]['is_primary'] = false;
            }
        }
        $this->save($student, $links);
        $this->audit->record('guardian.link_update', 'students', $student['_id'], [
            'guardian_id' => (string)$gid,
            'relationship' => $data['relationship'],
            'is_primary' => $primary,
            'receives_absence_notifications' => $notify,
        ]);
    }

    /**
     * Remove o vínculo (o cadastro do responsável continua existindo). Se ele era o principal, o próximo assume.
     *
     * @throws NotFoundException
     */
    public function unlink(string $studentId, string $guardianId): void
    {
        $student = $this->findStudent($studentId);
        $gid = Bson::objectId($guardianId);
        $position = $gid === null ? null : self::position($student, $gid);
        if ($position === null) {
            throw new NotFoundException('Este responsável não está vinculado ao aluno.');
        }
        $links = self::links($student);
        $wasPrimary = ($links[$position]['is_primary'] ?? false) === true;
        unset($links[$position]);
        $links = array_values($links);
        if ($wasPrimary && $links !== []) {
            $links[0]['is_primary'] = true;
        }
        $this->save($student, $links);
        $this->audit->record('guardian.unlink', 'students', $student['_id'], ['guardian_id' => (string)$gid]);
    }

    /**
     * Vínculos do aluno com os dados de contato de cada responsável (uma consulta em lote, sem N+1).
     *
     * @param array<string, mixed> $student documento do aluno
     * @return list<array<string, mixed>>
     */
    public function linkedGuardians(array $student): array
    {
        $links = self::links($student);
        $contacts = $this->guardians->findManyByIds(array_map(static fn (array $link): ObjectId => $link['guardian_id'], $links));
        $result = [];
        foreach ($links as $link) {
            $contact = $contacts[(string)$link['guardian_id']] ?? null;
            $result[] = [
                'id' => (string)$link['guardian_id'],
                'name' => (string)($contact['name'] ?? 'Responsável não encontrado'),
                'email' => (string)($contact['email'] ?? ''),
                'phone' => (string)($contact['phone'] ?? ''),
                'email_status' => (string)($contact['email_status'] ?? ''),
                'active' => ($contact['active'] ?? false) === true,
                'relationship' => (string)$link['relationship'],
                'is_primary' => ($link['is_primary'] ?? false) === true,
                'receives_absence_notifications' => ($link['receives_absence_notifications'] ?? false) === true,
            ];
        }
        return $result;
    }

    // ------------------------------------------------------------------ internos

    /** @param array<string, mixed> $input */
    private static function linkValidator(array $input): Validator
    {
        return Validator::make($input, ['relationship' => 'o parentesco', 'guardian_id' => 'o responsável'])
            ->required('relationship')
            ->in('relationship', Relationship::values());
    }

    /** @param array<string, mixed> $student */
    private static function assertRoom(array $student, Validator $validator): void
    {
        if (count(self::links($student)) >= self::MAX_LINKS) {
            $validator->addError('guardians', self::LIMIT_MESSAGE);
        }
    }

    /**
     * Acrescenta o vínculo: o primeiro vira principal; marcar como principal desmarca o anterior.
     *
     * @param array<string, mixed> $student
     * @param array<string, mixed> $guardian
     * @param array<string, mixed> $input is_primary, receives_absence_notifications
     */
    private function append(array $student, array $guardian, string $relationship, array $input): void
    {
        $links = self::links($student);
        $primary = $links === [] || ($input['is_primary'] ?? null) === true;
        if ($primary) {
            foreach ($links as $index => $link) {
                $links[$index]['is_primary'] = false;
            }
        }
        $links[] = [
            'guardian_id' => $guardian['_id'],
            'relationship' => $relationship,
            'is_primary' => $primary,
            'receives_absence_notifications' => ($input['receives_absence_notifications'] ?? null) !== false,
        ];
        $this->save($student, $links);
        $this->audit->record('guardian.link', 'students', $student['_id'], [
            'guardian_id' => (string)$guardian['_id'],
            'relationship' => $relationship,
            'is_primary' => $primary,
        ]);
    }

    /**
     * @param array<string, mixed> $student
     * @param list<array<string, mixed>> $links
     * @throws ConflictException
     */
    private function save(array $student, array $links): void
    {
        if (!$this->students->replaceGuardians($student['_id'], self::links($student), $links)) {
            throw new ConflictException(self::CHANGED_MESSAGE);
        }
    }

    /**
     * @param array<string, mixed> $student
     * @return list<array<string, mixed>>
     */
    private static function links(array $student): array
    {
        return array_values(array_filter((array)($student['guardians'] ?? []), 'is_array'));
    }

    /** @param array<string, mixed> $student */
    private static function position(array $student, ObjectId $guardianId): ?int
    {
        foreach (self::links($student) as $index => $link) {
            if ((string)($link['guardian_id'] ?? '') === (string)$guardianId) {
                return $index;
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function findStudent(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Aluno não encontrado.');
        return $this->students->findById($oid) ?? throw new NotFoundException('Aluno não encontrado.');
    }
}
