<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Core\Csrf;
use Saa\Core\Exceptions\AuthenticationException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\TooManyRequestsException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Session;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Domain\Profile;
use Saa\Models\LoginAttemptModel;
use Saa\Models\UserModel;
use Saa\Support\Email;
use Saa\Support\PasswordHasher;
use SensitiveParameter;

/**
 * Autenticação (issues #27, #31): login por e-mail e senha, limite de tentativas, sessão e criação de usuários.
 *
 * Segurança do login:
 *  - mensagem genérica para qualquer falha (e-mail inexistente, senha errada, usuário inativo);
 *  - password_verify() roda mesmo quando o usuário não existe (hash fictício): o tempo não revela o e-mail;
 *  - limite de LOGIN_MAX_ATTEMPTS falhas por e-mail+IP em LOGIN_WINDOW_MINUTES (depois, 429 + Retry-After);
 *  - o hash é atualizado se o custo/algoritmo mudou; a sessão é invalidada e recriada (anti-fixação) e o token CSRF renovado;
 *  - tudo auditado (LOGIN_SUCCESS, LOGIN_FAILED, LOGIN_BLOCKED, LOGOUT, USER_CREATED).
 */
final class AuthService
{
    public const GENERIC_FAILURE = 'E-mail ou senha inválidos.';

    public function __construct(
        private readonly UserModel $users,
        private readonly LoginAttemptModel $attempts,
        private readonly AuditService $audit,
        private readonly Session $session,
        private readonly Csrf $csrf,
    ) {
    }

    /**
     * Autentica e inicia a sessão do usuário.
     *
     * @return array{id: string, name: string, email: string, profile: string, last_login_at: mixed, created_at: mixed}
     * @throws AuthenticationException credenciais inválidas ou usuário inativo (mensagem sempre genérica)
     * @throws TooManyRequestsException limite de tentativas excedido
     */
    public function attempt(string $email, #[SensitiveParameter] string $password, string $ip): array
    {
        $normalized = Email::normalize($email);
        $key = LoginAttemptModel::keyFor($normalized, $ip);
        $this->assertNotLocked($key, $ip);

        $user = strlen($normalized) <= 254 ? $this->users->findByEmail($normalized, true) : null;
        $hash = is_array($user) && is_string($user['password_hash'] ?? null) ? $user['password_hash'] : PasswordHasher::dummyHash();
        $passwordMatches = PasswordHasher::verify($password, $hash);

        $failure = match (true) {
            $user === null => 'unknown_user',
            !$passwordMatches => 'bad_password',
            !self::canLogin($user) => 'inactive',
            default => null,
        };
        if ($failure !== null) {
            $this->attempts->record($key);
            $this->audit->record(
                AuditService::LOGIN_FAILED,
                'users',
                $user['_id'] ?? null,
                ['reason' => $failure, 'email' => Email::isValid($normalized) ? $normalized : null],
                null,
                $ip
            );
            throw new AuthenticationException(self::GENERIC_FAILURE);
        }

        if (PasswordHasher::needsRehash($hash)) {
            $this->users->updatePasswordHash($user['_id'], PasswordHasher::hash($password));
        }
        $this->users->touchLogin($user['_id']);
        $this->attempts->clear($key);

        $user['last_login_at'] = Bson::now();
        $identity = self::identity($user);
        $this->session->invalidate();
        $this->session->setUserId($identity['id']);
        $this->csrf->rotate();
        $this->audit->record(AuditService::LOGIN_SUCCESS, 'users', $user['_id'], [], $identity, $ip);
        return $identity;
    }

    /** Encerra a sessão (troca o identificador e o token CSRF) e audita a saída. */
    public function logout(?array $user, string $ip): void
    {
        if ($user !== null) {
            $this->audit->record(AuditService::LOGOUT, 'users', $user['id'] ?? null, [], $user, $ip);
        }
        $this->session->invalidate();
        $this->csrf->rotate();
    }

    /**
     * Usuário da sessão, relido do banco a cada requisição. Se foi removido, desativado ou perdeu o direito de
     * entrar, a sessão é encerrada (expiredReason() = "revoked") e o resultado é null.
     *
     * @return array{id: string, name: string, email: string, profile: string, last_login_at: mixed, created_at: mixed}|null
     */
    public function currentUser(): ?array
    {
        $id = $this->session->userId();
        if ($id === null) {
            return null;
        }
        $user = $this->users->findActiveById($id);
        if ($user === null || !self::canLogin($user)) {
            $this->session->invalidate('revoked');
            return null;
        }
        return self::identity($user);
    }

    /**
     * Cadastra um usuário interno (usado por bin/create_user.php e, na Etapa 2, pela tela de usuários).
     * Regras: nome de 3 a 120 caracteres, e-mail válido e único, senha de 10 a 72 caracteres, perfil com conta
     * (ADMIN, COORDINATION, AOE ou SECRETARY — GUARDIAN não tem login).
     *
     * @param array<string, mixed> $input name, email, password, profile
     * @return array{id: string, name: string, email: string, profile: string, last_login_at: mixed, created_at: mixed}
     * @throws ValidationException dados inválidos
     * @throws ConflictException e-mail já cadastrado
     */
    public function createUser(array $input, ObjectId|string|null $createdBy = null): array
    {
        $data = Validator::make($input, [
            'name' => 'o nome',
            'email' => 'o e-mail',
            'password' => 'a senha',
            'profile' => 'o perfil',
        ])
            ->required('name', 'email', 'password', 'profile')
            ->string('name', 120, 3)
            ->email('email')
            ->password('password')
            ->in('profile', Profile::loginValues())
            ->validate();

        $creator = Bson::objectId($createdBy);
        $user = $this->users->create($data['name'], $data['email'], PasswordHasher::hash($data['password']), $data['profile'], $creator);
        $this->audit->record(
            AuditService::USER_CREATED,
            'users',
            $user['_id'],
            ['profile' => $data['profile']],
            $creator !== null ? ['id' => (string)$creator] : null,
        );
        return self::identity($user);
    }

    /**
     * Forma pública do usuário (sem password_hash): é o que Request::user() e current_user() expõem.
     *
     * @param array<string, mixed> $user documento do MongoDB
     * @return array{id: string, name: string, email: string, profile: string, last_login_at: mixed, created_at: mixed}
     */
    public static function identity(array $user): array
    {
        return [
            'id' => (string)$user['_id'],
            'name' => (string)($user['name'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'profile' => (string)($user['profile'] ?? ''),
            'last_login_at' => $user['last_login_at'] ?? null,
            'created_at' => $user['created_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $user */
    private static function canLogin(array $user): bool
    {
        return ($user['active'] ?? false) === true && Profile::tryFromValue($user['profile'] ?? null)?->canLogin() === true;
    }

    private function assertNotLocked(string $key, string $ip): void
    {
        $max = max(1, Config::int('LOGIN_MAX_ATTEMPTS', 5));
        $window = max(1, Config::int('LOGIN_WINDOW_MINUTES', 15)) * 60;
        $now = Clock::now();
        $since = $now->modify('-' . $window . ' seconds');
        if ($this->attempts->countSince($key, $since) < $max) {
            return;
        }
        $oldest = $this->attempts->oldestSince($key, $since);
        $retryAfter = $oldest === null ? $window : max(1, $oldest->getTimestamp() + $window - $now->getTimestamp());
        $this->audit->record(AuditService::LOGIN_BLOCKED, 'users', null, ['retry_after_seconds' => $retryAfter], null, $ip);
        $minutes = (int)ceil($retryAfter / 60);
        throw new TooManyRequestsException(
            $retryAfter,
            'Muitas tentativas de acesso. Aguarde ' . $minutes . ($minutes === 1 ? ' minuto' : ' minutos') . ' e tente novamente.'
        );
    }
}
