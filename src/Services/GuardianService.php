<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Domain\EmailStatus;
use Saa\Models\GuardianModel;
use Saa\Models\StudentModel;
use Saa\Support\Email;

/**
 * Responsáveis (issue #39): cadastro do contato (nome, e-mail único normalizado, telefone brasileiro com DDD),
 * edição, arquivamento/reativação e ficha com os alunos vinculados. O vínculo com alunos fica em
 * GuardianLinkService (students.guardians embutido). Todas as escritas são auditadas.
 */
final class GuardianService
{
    private const PICKER_LIMIT = 15;

    public function __construct(
        private readonly GuardianModel $guardians,
        private readonly StudentModel $students,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, filters: array{q: string, status: string}}
     */
    public function list(?string $term, ?string $status, int $page): array
    {
        $term = trim((string)$term);
        $active = RegistrySupport::activeFilter($status);
        $regex = $term !== '' ? Bson::contains($term) : null;
        $page = RegistrySupport::page($page);
        $result = $this->guardians->search($regex, $active, ($page - 1) * RegistrySupport::PER_PAGE, RegistrySupport::PER_PAGE);
        $pagination = RegistrySupport::pagination($page, $result['total']);
        if ($pagination['page'] !== $page && $result['total'] > 0) {
            $result = $this->guardians->search($regex, $active, $pagination['skip'], RegistrySupport::PER_PAGE);
        }
        return [
            'items' => array_map(self::present(...), $result['items']),
            'pagination' => $pagination,
            'filters' => ['q' => mb_substr($term, 0, 100), 'status' => $active ? 'active' : 'archived'],
        ];
    }

    /**
     * Ficha do responsável com os alunos vinculados (turma atual e dados do vínculo).
     *
     * @return array{guardian: array<string, mixed>, students: list<array<string, mixed>>}
     * @throws NotFoundException
     */
    public function show(string $id): array
    {
        $guardian = $this->find($id);
        $students = [];
        foreach ($this->students->findByGuardian($guardian['_id']) as $student) {
            $link = null;
            foreach ((array)($student['guardians'] ?? []) as $item) {
                if ((string)$item['guardian_id'] === (string)$guardian['_id']) {
                    $link = $item;
                    break;
                }
            }
            $students[] = [
                'id' => (string)$student['_id'],
                'name' => (string)$student['name'],
                'student_code' => (string)$student['student_code'],
                'active' => ($student['active'] ?? false) === true,
                'class_code' => isset($student['class']['code']) ? (string)$student['class']['code'] : null,
                'class_name' => isset($student['class']['name']) ? (string)$student['class']['name'] : null,
                'relationship' => (string)($link['relationship'] ?? ''),
                'is_primary' => ($link['is_primary'] ?? false) === true,
                'receives_absence_notifications' => ($link['receives_absence_notifications'] ?? false) === true,
            ];
        }
        return ['guardian' => self::present($guardian), 'students' => $students];
    }

    /**
     * Valida os dados de contato. $prefix permite usar o mesmo formulário dentro de outra tela
     * (ex.: "guardian_" na ficha do aluno: guardian_name, guardian_email, guardian_phone).
     *
     * @param array<string, mixed> $input
     * @return Validator validador com name, email e phone (com o prefixo) já conferidos
     */
    public function contactValidator(array $input, string $prefix = '', bool $withStatus = false): Validator
    {
        $name = $prefix . 'name';
        $email = $prefix . 'email';
        $phone = $prefix . 'phone';
        $validator = Validator::make($input, [
            $name => 'o nome do responsável',
            $email => 'o e-mail do responsável',
            $phone => 'o telefone com DDD',
            'email_status' => 'a situação do e-mail',
        ])
            ->required($name, $email, $phone)
            ->string($name, 120, 2)
            ->email($email)
            ->string($phone, 30);
        if ($withStatus) {
            $validator->required('email_status')->in('email_status', EmailStatus::values());
        }
        $typedPhone = $validator->validated()[$phone] ?? null;
        if (is_string($typedPhone) && !RegistrySupport::isPhone($typedPhone)) {
            $validator->addError($phone, RegistrySupport::PHONE_MESSAGE);
        }
        return $validator;
    }

    /**
     * Grava um responsável a partir de dados já validados por contactValidator().
     *
     * @return array<string, mixed> documento gravado
     * @throws ValidationException e-mail já cadastrado (no campo {$prefix}email)
     */
    public function insertValidated(string $name, string $email, string $phone, string $prefix = ''): array
    {
        $now = Bson::now();
        try {
            $guardian = $this->guardians->insert([
                'name' => $name,
                'email' => $email,
                'email_normalized' => Email::normalize($email),
                'email_status' => EmailStatus::VALID->value,
                'phone' => $phone,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (ConflictException) {
            throw new ValidationException([$prefix . 'email' => 'Já existe um responsável com este e-mail.']);
        }
        $this->audit->record('guardian.create', 'guardians', $guardian['_id'], ['email' => $guardian['email_normalized']]);
        return $guardian;
    }

    /**
     * Edita nome, e-mail, telefone e situação do e-mail.
     *
     * @param array<string, mixed> $input name, email, phone, email_status
     * @return array<string, mixed>
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(string $id, array $input): array
    {
        $guardian = $this->find($id);
        $data = $this->contactValidator($input, '', true)->validate();
        $fields = [
            'name' => $data['name'],
            'email' => $data['email'],
            'email_normalized' => Email::normalize($data['email']),
            'email_status' => $data['email_status'],
            'phone' => $data['phone'],
        ];
        try {
            $this->guardians->update($guardian['_id'], $fields);
        } catch (ConflictException) {
            throw new ValidationException(['email' => 'Já existe um responsável com este e-mail.']);
        }
        $this->audit->record('guardian.update', 'guardians', $guardian['_id'], [
            'email_changed' => $fields['email_normalized'] !== ($guardian['email_normalized'] ?? null),
            'email_status' => $fields['email_status'],
        ]);
        return self::present($this->find($id));
    }

    /** @throws NotFoundException */
    public function archive(string $id): void
    {
        $this->setActive($id, false, 'guardian.archive');
    }

    /** @throws NotFoundException */
    public function reactivate(string $id): void
    {
        $this->setActive($id, true, 'guardian.reactivate');
    }

    /**
     * Responsáveis ativos para o seletor de vínculo, sem os já vinculados.
     *
     * @param list<ObjectId> $exclude
     * @return list<array<string, mixed>>
     */
    public function pickable(string $term, array $exclude): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }
        return array_map(self::present(...), $this->guardians->pickable(Bson::contains($term), $exclude, self::PICKER_LIMIT));
    }

    /**
     * @param array<string, mixed> $guardian documento do MongoDB
     * @return array<string, mixed>
     */
    public static function present(array $guardian): array
    {
        return [
            'id' => (string)$guardian['_id'],
            'name' => (string)($guardian['name'] ?? ''),
            'email' => (string)($guardian['email'] ?? ''),
            'email_status' => (string)($guardian['email_status'] ?? ''),
            'phone' => (string)($guardian['phone'] ?? ''),
            'active' => ($guardian['active'] ?? false) === true,
            'student_count' => (int)($guardian['student_count'] ?? 0),
        ];
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Responsável não encontrado.');
        return $this->guardians->findById($oid) ?? throw new NotFoundException('Responsável não encontrado.');
    }

    private function setActive(string $id, bool $active, string $action): void
    {
        $guardian = $this->find($id);
        if (($guardian['active'] ?? false) === $active) {
            return;
        }
        $this->guardians->setActive($guardian['_id'], $active);
        $this->audit->record($action, 'guardians', $guardian['_id']);
    }
}
