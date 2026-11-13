<?php
declare(strict_types=1);

namespace Saa\Services;

use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Domain\Profile;
use Saa\Models\UserModel;
use Saa\Support\PasswordHasher;
use SensitiveParameter;

/**
 * Usuários internos (issues #36 e #37), tela exclusiva do perfil ADMIN (users.manage):
 *  - listagem paginada (20 por página) com busca por nome ou e-mail e filtros de perfil e situação;
 *  - cadastro (reaproveita AuthService::createUser: mesmas regras do bin/create_user.php) com confirmação de senha;
 *  - edição de nome, perfil e situação; nova senha definida pelo administrador (atualiza password_changed_at).
 *
 * Regras de proteção do acesso administrativo:
 *  - o administrador não pode desativar a si mesmo nem retirar o próprio perfil ADMIN;
 *  - o sistema sempre mantém pelo menos um ADMIN ativo.
 * Nada aqui devolve password_hash. Auditoria: user.create, user.update, user.password_reset.
 */
final class UserService
{
    public const ACTION_CREATE = 'user.create';
    public const ACTION_UPDATE = 'user.update';
    public const ACTION_PASSWORD_RESET = 'user.password_reset';
    public const ENTITY = 'users';

    public const DUPLICATE_EMAIL = 'Já existe um usuário com este e-mail.';
    public const CONFIRMATION_MISMATCH = 'A confirmação não confere com a senha digitada.';
    public const SELF_DEACTIVATE = 'Você não pode desativar o seu próprio usuário.';
    public const SELF_DEMOTE = 'Você não pode retirar o seu próprio perfil de Administrador.';
    public const LAST_ADMIN = 'O sistema precisa manter pelo menos um administrador ativo.';

    private const LABELS = [
        'name' => 'o nome',
        'email' => 'o e-mail',
        'profile' => 'o perfil',
        'password' => 'a senha',
        'password_confirmation' => 'a confirmação da senha',
        'active' => 'a situação',
    ];

    public function __construct(
        private readonly UserModel $users,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * @param string|null $profile código do perfil (vazio ou inválido = todos)
     * @param string|null $status  "active", "inactive" ou vazio (todos)
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>, filters: array{q: string, profile: string, status: string}}
     */
    public function list(?string $term, ?string $profile, ?string $status, int $page): array
    {
        $term = mb_substr(trim((string)$term), 0, 100);
        $regex = $term !== '' ? Bson::contains($term) : null;
        $profileCode = in_array($profile, Profile::loginValues(), true) ? $profile : null;
        $active = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
        $page = RegistrySupport::page($page);
        $result = $this->users->search($regex, $profileCode, $active, ($page - 1) * RegistrySupport::PER_PAGE, RegistrySupport::PER_PAGE);
        $pagination = RegistrySupport::pagination($page, $result['total']);
        if ($pagination['page'] !== $page && $result['total'] > 0) {
            $result = $this->users->search($regex, $profileCode, $active, $pagination['skip'], RegistrySupport::PER_PAGE);
        }
        return [
            'items' => array_map(self::present(...), $result['items']),
            'pagination' => $pagination,
            'filters' => [
                'q' => $term,
                'profile' => $profileCode ?? '',
                'status' => $active === null ? '' : ($active ? 'active' : 'inactive'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     * @throws NotFoundException
     */
    public function get(string $id): array
    {
        return self::present($this->find($id));
    }

    /**
     * Cadastra um usuário interno. A senha precisa ser repetida em password_confirmation.
     *
     * @param array<string, mixed> $input name, email, profile, password, password_confirmation
     * @param array{id?: string, name?: string}|null $actor administrador que está cadastrando
     * @return array<string, mixed> usuário criado (sem password_hash)
     * @throws ValidationException dados inválidos ou e-mail já cadastrado (erro no campo email)
     */
    public function create(#[SensitiveParameter] array $input, ?array $actor = null): array
    {
        // Mesmas regras de AuthService::createUser, repetidas aqui para mostrar TODOS os erros de uma vez
        // (inclusive o da confirmação), em vez de um erro por envio.
        $validator = Validator::make($input, self::LABELS)
            ->required('name', 'email', 'profile', 'password', 'password_confirmation')
            ->string('name', 120, 3)
            ->email('email')
            ->in('profile', Profile::loginValues())
            ->password('password');
        self::checkConfirmation($validator, $input);
        $validator->validate();

        try {
            $identity = $this->auth->createUser([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'profile' => $input['profile'],
            ], $actor['id'] ?? null);
        } catch (ConflictException) {
            throw new ValidationException(['email' => self::DUPLICATE_EMAIL]);
        }
        $this->audit->record(self::ACTION_CREATE, self::ENTITY, $identity['id'], [
            'email' => $identity['email'],
            'profile' => $identity['profile'],
        ], $actor);
        return $this->get($identity['id']);
    }

    /**
     * Altera nome, perfil e situação.
     *
     * @param array<string, mixed> $input name, profile, active (bool ou "1"/"0")
     * @param array{id?: string, name?: string}|null $actor administrador que está alterando
     * @return array<string, mixed> usuário atualizado
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function update(string $id, array $input, ?array $actor = null): array
    {
        $user = $this->find($id);
        $validator = Validator::make($input, self::LABELS)
            ->required('name', 'profile')
            ->string('name', 120, 3)
            ->in('profile', Profile::loginValues());
        $data = $validator->validated();
        $active = self::flag($input['active'] ?? null);
        if ($active === null) {
            $validator->addError('active', 'Selecione uma situação válida.');
        }

        $wasAdmin = ($user['profile'] ?? null) === Profile::ADMIN->value && ($user['active'] ?? false) === true;
        $isSelf = isset($actor['id']) && (string)$actor['id'] === (string)$user['_id'];
        $newProfile = $data['profile'] ?? null;
        if ($isSelf && $active === false) {
            $validator->addError('active', self::SELF_DEACTIVATE);
        }
        if ($isSelf && $newProfile !== null && $newProfile !== Profile::ADMIN->value && ($user['profile'] ?? null) === Profile::ADMIN->value) {
            $validator->addError('profile', self::SELF_DEMOTE);
        }
        if ($wasAdmin && $validator->passes() && ($newProfile !== Profile::ADMIN->value || $active === false)
            && $this->users->countActiveAdmins($user['_id']) === 0) {
            $validator->addError($newProfile !== Profile::ADMIN->value ? 'profile' : 'active', self::LAST_ADMIN);
        }
        $validator->validate();

        $fields = ['name' => (string)$data['name'], 'profile' => (string)$newProfile, 'active' => (bool)$active];
        $this->users->updateAccount($user['_id'], $fields);

        $changes = [];
        foreach ($fields as $field => $value) {
            if (($user[$field] ?? null) !== $value) {
                $changes[$field] = $field === 'name' ? true : ['from' => $user[$field] ?? null, 'to' => $value];
            }
        }
        $this->audit->record(self::ACTION_UPDATE, self::ENTITY, $user['_id'], ['changes' => $changes === [] ? new \stdClass() : $changes], $actor);
        return $this->get((string)$user['_id']);
    }

    /**
     * O administrador define uma nova senha para o usuário (mínimo de 10 caracteres, repetida na confirmação).
     *
     * @param array<string, mixed> $input password, password_confirmation
     * @param array{id?: string, name?: string}|null $actor
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function resetPassword(string $id, #[SensitiveParameter] array $input, ?array $actor = null): void
    {
        $user = $this->find($id);
        $validator = Validator::make($input, ['password' => 'a nova senha', 'password_confirmation' => 'a confirmação da senha'])
            ->required('password', 'password_confirmation')
            ->password('password');
        self::checkConfirmation($validator, $input);
        $validator->validate();

        $this->users->setPassword($user['_id'], PasswordHasher::hash((string)$input['password']));
        $this->audit->record(self::ACTION_PASSWORD_RESET, self::ENTITY, $user['_id'], [
            'self' => isset($actor['id']) && (string)$actor['id'] === (string)$user['_id'],
        ], $actor);
    }

    /**
     * Forma de exibição (nunca inclui password_hash).
     *
     * @param array<string, mixed> $user documento do MongoDB
     * @return array{id: string, name: string, email: string, profile: string, active: bool, last_login_at: mixed, password_changed_at: mixed, created_at: mixed}
     */
    public static function present(array $user): array
    {
        return [
            'id' => (string)$user['_id'],
            'name' => (string)($user['name'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'profile' => (string)($user['profile'] ?? ''),
            'active' => ($user['active'] ?? false) === true,
            'last_login_at' => $user['last_login_at'] ?? null,
            'password_changed_at' => $user['password_changed_at'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];
    }

    /** @return array<string, string> código => rótulo dos perfis que podem ter conta */
    public static function profileOptions(): array
    {
        $options = [];
        foreach (Profile::loginValues() as $code) {
            $options[$code] = Profile::from($code)->label();
        }
        return $options;
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $oid = RegistrySupport::idOrFail($id, 'Usuário não encontrado.');
        return $this->users->findById($oid) ?? throw new NotFoundException('Usuário não encontrado.');
    }

    /** @param array<string, mixed> $input */
    private static function checkConfirmation(Validator $validator, array $input): void
    {
        $password = $input['password'] ?? null;
        $confirmation = $input['password_confirmation'] ?? null;
        if (is_string($password) && $password !== '' && is_string($confirmation) && $confirmation !== ''
            && !hash_equals($password, $confirmation)) {
            $validator->addError('password_confirmation', self::CONFIRMATION_MISMATCH);
        }
    }

    /** "1"/"0", true/false, "true"/"false", "on" → bool; qualquer outro valor → null. */
    private static function flag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return match (is_string($value) ? strtolower(trim($value)) : $value) {
            '1', 'true', 'on', 1 => true,
            '0', 'false', 0 => false,
            default => null,
        };
    }
}
