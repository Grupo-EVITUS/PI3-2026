<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Services;

use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Core\Csrf;
use Saa\Core\Exceptions\AuthenticationException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\TooManyRequestsException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Session;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Models\AuditEventModel;
use Saa\Models\LoginAttemptModel;
use Saa\Models\UserModel;
use Saa\Services\AuditService;
use Saa\Services\AuthService;
use Saa\Tests\Support\MongoTestCase;

/** Login, limite de tentativas, auditoria e criação de usuários contra o MongoDB real (issues #27, #31, #36). */
final class AuthServiceTest extends MongoTestCase
{
    private const PASSWORD = 'SenhaForte123!';
    private const IP = '203.0.113.10';

    private Session $session;
    private AuthService $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('PASSWORD_COST', '4');           // testes rápidos (produção nunca usa custo < 10)
        Config::set('LOGIN_MAX_ATTEMPTS', '3');
        Config::set('LOGIN_WINDOW_MINUTES', '15');
        $this->session = Session::memory();
        $this->session->start();
        $this->auth = new AuthService(
            new UserModel($this->db()),
            new LoginAttemptModel($this->db()),
            new AuditService(new AuditEventModel($this->db())),
            $this->session,
            new Csrf($this->session),
        );
    }

    /** @return array<string, mixed> */
    private function createUser(string $email = 'aoe@escola.local', string $profile = 'AOE'): array
    {
        return $this->auth->createUser(['name' => 'Ana AOE', 'email' => $email, 'password' => self::PASSWORD, 'profile' => $profile]);
    }

    /** @return list<array<string, mixed>> */
    private function audit(string $action): array
    {
        return Mongo::collection(Collections::AUDIT_EVENTS)->find(['action' => $action])->toArray();
    }

    private function assertGenericFailure(callable $login): void
    {
        try {
            $login();
            self::fail('o login deveria falhar');
        } catch (AuthenticationException $error) {
            self::assertSame(AuthService::GENERIC_FAILURE, $error->getMessage());
        }
    }

    public function testValidLoginStartsTheSessionAuditsAndRotatesTheCsrfToken(): void
    {
        $created = $this->createUser();
        $csrfBefore = (new Csrf($this->session))->token();

        $identity = $this->auth->attempt('  AOE@Escola.Local ', self::PASSWORD, self::IP);

        self::assertSame($created['id'], $identity['id']);
        self::assertSame(['id', 'name', 'email', 'profile', 'last_login_at', 'created_at'], array_keys($identity));
        self::assertSame($created['id'], $this->session->userId());
        self::assertNotSame($csrfBefore, (new Csrf($this->session))->token(), 'token CSRF renovado no login');

        $success = $this->audit(AuditService::LOGIN_SUCCESS);
        self::assertCount(1, $success);
        self::assertSame(self::IP, $success[0]['ip']);
        self::assertSame($created['id'], (string)$success[0]['actor_user_id']);
        self::assertNotNull(Mongo::collection(Collections::USERS)->findOne(['email_normalized' => 'aoe@escola.local'])['last_login_at']);
    }

    public function testWrongPasswordUnknownEmailAndInactiveUserFailWithTheSameMessage(): void
    {
        $this->createUser();
        $this->createUser('inativa@escola.local');
        Mongo::collection(Collections::USERS)->updateOne(['email_normalized' => 'inativa@escola.local'], ['$set' => ['active' => false]]);

        $this->assertGenericFailure(fn () => $this->auth->attempt('aoe@escola.local', 'senha-errada-123', self::IP));
        $this->assertGenericFailure(fn () => $this->auth->attempt('ninguem@escola.local', self::PASSWORD, '198.51.100.7'));
        $this->assertGenericFailure(fn () => $this->auth->attempt('inativa@escola.local', self::PASSWORD, '198.51.100.8'));

        $reasons = array_map(static fn (array $event): string => $event['details']['reason'], $this->audit(AuditService::LOGIN_FAILED));
        self::assertEqualsCanonicalizing(['bad_password', 'unknown_user', 'inactive'], $reasons);
        self::assertNull($this->session->userId(), 'nenhuma falha autentica');
    }

    public function testAttemptsAreLimitedPerEmailAndIpEvenWithTheRightPassword(): void
    {
        $this->createUser();
        Clock::fix('2026-11-04T12:00:00Z');
        for ($i = 0; $i < 3; $i++) {
            $this->assertGenericFailure(fn () => $this->auth->attempt('aoe@escola.local', 'errada-' . $i . '-xyz', self::IP));
        }

        try {
            $this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP);
            self::fail('a quarta tentativa deveria ser bloqueada');
        } catch (TooManyRequestsException $blocked) {
            self::assertGreaterThan(0, $blocked->retryAfterSeconds());
            self::assertLessThanOrEqual(15 * 60, $blocked->retryAfterSeconds());
            self::assertStringContainsString('Aguarde 15 minutos', $blocked->getMessage());
        }
        self::assertCount(1, $this->audit(AuditService::LOGIN_BLOCKED));

        // outro IP não herda o bloqueio; e, passada a janela, o mesmo IP volta a entrar
        self::assertSame('aoe@escola.local', $this->auth->attempt('aoe@escola.local', self::PASSWORD, '198.51.100.9')['email']);
        Clock::fix('2026-11-04T12:16:00Z');
        self::assertSame('aoe@escola.local', $this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP)['email']);
    }

    public function testSuccessfulLoginClearsPreviousFailures(): void
    {
        $this->createUser();
        $this->assertGenericFailure(fn () => $this->auth->attempt('aoe@escola.local', 'errada-1-xyz', self::IP));
        $this->assertGenericFailure(fn () => $this->auth->attempt('aoe@escola.local', 'errada-2-xyz', self::IP));
        $this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP);

        $this->assertGenericFailure(fn () => $this->auth->attempt('aoe@escola.local', 'errada-3-xyz', self::IP));
        $this->assertGenericFailure(fn () => $this->auth->attempt('aoe@escola.local', 'errada-4-xyz', self::IP));
        self::assertNotEmpty($this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP)['id'], 'contador zerado após o sucesso');
    }

    public function testOutdatedHashIsUpgradedTransparentlyOnLogin(): void
    {
        $id = $this->createUser()['id'];
        $old = password_hash(self::PASSWORD, PASSWORD_DEFAULT, ['cost' => 5]);
        Mongo::collection(Collections::USERS)->updateOne(['email_normalized' => 'aoe@escola.local'], ['$set' => ['password_hash' => $old]]);

        $this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP);

        $stored = Mongo::collection(Collections::USERS)->findOne(['email_normalized' => 'aoe@escola.local'])['password_hash'];
        self::assertNotSame($old, $stored);
        self::assertStringStartsWith('$2y$04$', $stored, 'hash refeito com o custo configurado');
        self::assertSame($id, $this->session->userId());
    }

    public function testDeactivatedUserLosesTheSessionOnTheNextRequest(): void
    {
        $this->createUser();
        $this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP);
        self::assertNotNull($this->auth->currentUser());

        Mongo::collection(Collections::USERS)->updateOne(['email_normalized' => 'aoe@escola.local'], ['$set' => ['active' => false]]);

        self::assertNull($this->auth->currentUser());
        self::assertSame('revoked', $this->session->expiredReason());
        self::assertNull($this->session->userId());
    }

    public function testLogoutIsAuditedAndEndsTheSession(): void
    {
        $this->createUser();
        $identity = $this->auth->attempt('aoe@escola.local', self::PASSWORD, self::IP);

        $this->auth->logout($identity, self::IP);

        self::assertNull($this->session->userId());
        self::assertCount(1, $this->audit(AuditService::LOGOUT));
    }

    public function testCreateUserValidatesTheInputAndAuditsTheCreation(): void
    {
        $invalid = [
            'senha curta' => ['name' => 'Ana', 'email' => 'a@escola.local', 'password' => 'curta', 'profile' => 'AOE'],
            'responsável sem login' => ['name' => 'Ana Souza', 'email' => 'a@escola.local', 'password' => self::PASSWORD, 'profile' => 'GUARDIAN'],
            'perfil inexistente' => ['name' => 'Ana Souza', 'email' => 'a@escola.local', 'password' => self::PASSWORD, 'profile' => 'DIRETOR'],
            'e-mail inválido' => ['name' => 'Ana Souza', 'email' => 'sem-arroba', 'password' => self::PASSWORD, 'profile' => 'AOE'],
            'nome curto' => ['name' => 'An', 'email' => 'a@escola.local', 'password' => self::PASSWORD, 'profile' => 'AOE'],
            'operador NoSQL' => ['name' => ['$ne' => ''], 'email' => 'a@escola.local', 'password' => self::PASSWORD, 'profile' => 'AOE'],
        ];
        foreach ($invalid as $case => $input) {
            try {
                $this->auth->createUser($input);
                self::fail("deveria recusar: {$case}");
            } catch (ValidationException $error) {
                self::assertNotEmpty($error->errors(), $case);
            }
        }
        self::assertSame(0, Mongo::collection(Collections::USERS)->countDocuments([]));

        $created = $this->createUser('secretaria@escola.local', 'SECRETARY');
        self::assertSame('SECRETARY', $created['profile']);
        self::assertCount(1, $this->audit(AuditService::USER_CREATED));

        $this->expectException(ConflictException::class);
        $this->createUser('SECRETARIA@escola.local');
    }
}
