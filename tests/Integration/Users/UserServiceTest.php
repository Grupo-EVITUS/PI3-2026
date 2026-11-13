<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Users;

use Saa\Core\Config;
use Saa\Core\Csrf;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Session;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Models\AuditEventModel;
use Saa\Models\LoginAttemptModel;
use Saa\Models\UserModel;
use Saa\Services\AuditService;
use Saa\Services\AuthService;
use Saa\Services\UserService;
use Saa\Support\PasswordHasher;
use Saa\Tests\Support\MongoTestCase;

/** Cadastro (#36) e consulta (#37) de usuários, com as regras de proteção do acesso administrativo. */
final class UserServiceTest extends MongoTestCase
{
    private const PASSWORD = 'SenhaForte123!';

    private UserService $users;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('PASSWORD_COST', '4');
        $session = Session::memory();
        $session->start();
        $model = new UserModel($this->db());
        $audit = new AuditService(new AuditEventModel($this->db()));
        $auth = new AuthService($model, new LoginAttemptModel($this->db()), $audit, $session, new Csrf($session));
        $this->users = new UserService($model, $auth, $audit);
    }

    /** @return array<string, mixed> */
    private function create(string $name, string $email, string $profile = 'AOE', ?array $actor = null): array
    {
        return $this->users->create([
            'name' => $name, 'email' => $email, 'profile' => $profile,
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ], $actor);
    }

    /** @return array<string, string> */
    private static function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $error) {
            return $error->errors();
        }
        self::fail('esperava erro de validação');
    }

    private function auditCount(string $action): int
    {
        return Mongo::collection(Collections::AUDIT_EVENTS)->countDocuments(['action' => $action]);
    }

    public function testIssue36CreatesAUserWithHashedPasswordAndAuditsIt(): void
    {
        $admin = $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $created = $this->create('Bruno AOE', 'Bruno@Escola.test', 'AOE', $admin);

        self::assertSame('AOE', $created['profile']);
        self::assertTrue($created['active']);
        self::assertArrayNotHasKey('password_hash', $created);
        $stored = Mongo::collection(Collections::USERS)->findOne(['email_normalized' => 'bruno@escola.test']);
        self::assertTrue(PasswordHasher::verify(self::PASSWORD, $stored['password_hash']));
        self::assertSame($admin['id'], (string)$stored['created_by']);
        self::assertSame(2, $this->auditCount(UserService::ACTION_CREATE));
    }

    public function testIssue36ValidationReportsEveryFieldAtOnceIncludingTheConfirmation(): void
    {
        $errors = self::errorsOf(fn () => $this->users->create([
            'name' => 'Al', 'email' => 'sem-arroba', 'profile' => 'GUARDIAN',
            'password' => 'SenhaForte123!', 'password_confirmation' => 'OutraSenha123!',
        ]));

        self::assertSame(['name', 'email', 'profile', 'password_confirmation'], array_keys($errors));
        self::assertSame(UserService::CONFIRMATION_MISMATCH, $errors['password_confirmation']);
        self::assertArrayHasKey('password', self::errorsOf(fn () => $this->users->create([
            'name' => 'Alice Lima', 'email' => 'a@escola.test', 'profile' => 'AOE', 'password' => 'curta', 'password_confirmation' => 'curta',
        ])));
        self::assertSame(0, Mongo::collection(Collections::USERS)->countDocuments());
    }

    public function testIssue36DuplicateEmailIsAFieldError(): void
    {
        $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $errors = self::errorsOf(fn () => $this->create('Outra Ana', 'ADMIN@escola.test'));

        self::assertSame(UserService::DUPLICATE_EMAIL, $errors['email']);
    }

    public function testIssue37ListSearchesByNameOrEmailAndFiltersByProfileAndStatus(): void
    {
        $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $bruno = $this->create('Bruno Lima', 'bruno@escola.test', 'AOE');
        $this->create('Carla Souza', 'secretaria@escola.test', 'SECRETARY');
        $this->users->update($bruno['id'], ['name' => 'Bruno Lima', 'profile' => 'AOE', 'active' => '0']);

        $names = static fn (array $list): array => array_column($list['items'], 'name');
        self::assertCount(3, $this->users->list(null, null, null, 1)['items']);
        self::assertSame(['Bruno Lima'], $names($this->users->list('bru', null, null, 1)));
        self::assertSame(['Carla Souza'], $names($this->users->list('SECRETARIA@', null, null, 1)), 'busca por e-mail, sem diferenciar maiúsculas');
        self::assertSame(['Ana Admin'], $names($this->users->list(null, 'ADMIN', null, 1)));
        self::assertSame(['Bruno Lima'], $names($this->users->list(null, null, 'inactive', 1)));
        self::assertCount(2, $this->users->list(null, null, 'active', 1)['items']);
        self::assertSame([], $names($this->users->list('.*', null, null, 1)), 'o termo é texto, não expressão regular');
        foreach ($this->users->list(null, null, null, 1)['items'] as $item) {
            self::assertArrayNotHasKey('password_hash', $item);
        }
    }

    public function testIssue37ListIsPaginatedWithTwentyPerPage(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->create(sprintf('Usuário %02d', $i), "u{$i}@escola.test");
        }

        $first = $this->users->list(null, null, null, 1);
        $second = $this->users->list(null, null, null, 2);

        self::assertCount(20, $first['items']);
        self::assertCount(3, $second['items']);
        self::assertSame(23, $first['pagination']['total']);
    }

    public function testAdministratorCannotDeactivateOrDemoteHimself(): void
    {
        $admin = $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $this->create('Outro Admin', 'admin2@escola.test', 'ADMIN');

        $deactivate = self::errorsOf(fn () => $this->users->update($admin['id'], ['name' => 'Ana Admin', 'profile' => 'ADMIN', 'active' => '0'], $admin));
        $demote = self::errorsOf(fn () => $this->users->update($admin['id'], ['name' => 'Ana Admin', 'profile' => 'AOE', 'active' => '1'], $admin));

        self::assertSame(UserService::SELF_DEACTIVATE, $deactivate['active']);
        self::assertSame(UserService::SELF_DEMOTE, $demote['profile']);
    }

    public function testTheLastActiveAdministratorIsAlwaysKept(): void
    {
        $admin = $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $other = $this->create('Outro Admin', 'admin2@escola.test', 'ADMIN');

        // com dois administradores, um deles pode ser rebaixado por outra pessoa
        $this->users->update($other['id'], ['name' => 'Outro Admin', 'profile' => 'COORDINATION', 'active' => '1'], $admin);
        // o que sobrou não pode ser desativado nem rebaixado, nem por terceiros
        $deactivate = self::errorsOf(fn () => $this->users->update($admin['id'], ['name' => 'Ana Admin', 'profile' => 'ADMIN', 'active' => '0'], $other));
        $demote = self::errorsOf(fn () => $this->users->update($admin['id'], ['name' => 'Ana Admin', 'profile' => 'AOE', 'active' => '1'], $other));

        self::assertSame(UserService::LAST_ADMIN, $deactivate['active']);
        self::assertSame(UserService::LAST_ADMIN, $demote['profile']);
        self::assertSame('ADMIN', $this->users->get($admin['id'])['profile']);
    }

    public function testUpdateChangesNameProfileAndStatusAndAuditsTheChanges(): void
    {
        $admin = $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $user = $this->create('Bruno Lima', 'bruno@escola.test', 'AOE');

        $updated = $this->users->update($user['id'], ['name' => 'Bruno de Lima', 'profile' => 'SECRETARY', 'active' => '0'], $admin);

        self::assertSame(['Bruno de Lima', 'SECRETARY', false], [$updated['name'], $updated['profile'], $updated['active']]);
        $event = Mongo::collection(Collections::AUDIT_EVENTS)->findOne(['action' => UserService::ACTION_UPDATE]);
        self::assertSame(['from' => 'AOE', 'to' => 'SECRETARY'], $event['details']['changes']['profile']);
        self::assertArrayHasKey('active', self::errorsOf(fn () => $this->users->update($user['id'], ['name' => 'Bruno', 'profile' => 'AOE', 'active' => 'talvez'], $admin)));
    }

    public function testAdministratorSetsANewPasswordAndTheChangeDateIsUpdated(): void
    {
        $admin = $this->create('Ana Admin', 'admin@escola.test', 'ADMIN');
        $user = $this->create('Bruno Lima', 'bruno@escola.test', 'AOE');
        $before = Mongo::collection(Collections::USERS)->findOne(['email_normalized' => 'bruno@escola.test']);

        usleep(5000);
        $this->users->resetPassword($user['id'], ['password' => 'NovaSenha456!x', 'password_confirmation' => 'NovaSenha456!x'], $admin);

        $after = Mongo::collection(Collections::USERS)->findOne(['email_normalized' => 'bruno@escola.test']);
        self::assertTrue(PasswordHasher::verify('NovaSenha456!x', $after['password_hash']));
        self::assertFalse(PasswordHasher::verify(self::PASSWORD, $after['password_hash']));
        self::assertGreaterThan((string)$before['password_changed_at'], (string)$after['password_changed_at']);
        self::assertSame(1, $this->auditCount(UserService::ACTION_PASSWORD_RESET));
        $mismatch = self::errorsOf(fn () => $this->users->resetPassword($user['id'], ['password' => 'NovaSenha456!x', 'password_confirmation' => 'Diferente456!x']));
        self::assertSame(UserService::CONFIRMATION_MISMATCH, $mismatch['password_confirmation']);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->users->get('507f1f77bcf86cd799439011');
    }
}
