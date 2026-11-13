<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Users;

use Saa\Core\Clock;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Tests\Acceptance\Registry\RegistryHttpTestCase;

/** Tela de usuários (#36, #37) e tela inicial com dados reais (#28), pelo servidor HTTP de verdade. */
final class UsersAndHomeTest extends RegistryHttpTestCase
{
    private const NEW_PASSWORD = 'SenhaNova123!';

    public function testIssue36OnlyAdministratorsReachTheUsersArea(): void
    {
        foreach (['AOE', 'COORDINATION', 'SECRETARY'] as $profile) {
            $this->http->clearCookies();
            $this->signIn($profile, strtolower($profile) . '@escola.local');
            self::assertSame(403, $this->http->get('/users')->status, "{$profile} não acessa /users");
            self::assertSame(403, $this->send('/users', ['name' => 'X'])->status, "{$profile} não cadastra usuários");
            self::assertStringNotContainsString('href="/users"', $this->http->get('/home')->body, 'menu não mostra Usuários');
        }
        $this->http->clearCookies();
        self::assertSame(303, $this->http->get('/users')->status, 'visitante vai para o login');
    }

    public function testIssue36AdministratorCreatesAUserWhoCanThenLogIn(): void
    {
        $this->signIn('ADMIN', 'admin@escola.local');

        $created = $this->send('/users', [
            'name' => 'Bruno Lima', 'email' => 'bruno@escola.local', 'profile' => 'AOE',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]);

        self::assertSame(303, $created->status);
        $page = $this->http->get((string)$created->location());
        self::assertStringContainsString('Bruno Lima', $page->body);
        self::assertStringContainsString('cadastrado com o perfil AOE', $page->body, 'mensagem de sucesso');
        self::assertStringNotContainsString('password_hash', $page->body);

        $this->http->clearCookies();
        self::assertSame(303, $this->login('bruno@escola.local', self::NEW_PASSWORD)->status);
    }

    public function testIssue36InvalidDataReturns422WithMessagesAndKeepsTypedValuesButNotPasswords(): void
    {
        $this->signIn('ADMIN', 'admin@escola.local');

        $response = $this->send('/users', [
            'name' => 'Bruno Lima', 'email' => 'admin@escola.local', 'profile' => 'AOE',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => 'Diferente123!',
        ]);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('A confirmação não confere', $response->body);
        self::assertStringContainsString('value="Bruno Lima"', $response->body);
        self::assertStringNotContainsString(self::NEW_PASSWORD, $response->body);

        // com os demais campos válidos, o e-mail repetido vira erro do campo (não um erro 500)
        $duplicate = $this->send('/users', [
            'name' => 'Bruno Lima', 'email' => 'ADMIN@escola.local', 'profile' => 'AOE',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ]);
        self::assertSame(422, $duplicate->status);
        self::assertStringContainsString('Já existe um usuário com este e-mail.', $duplicate->body);
        self::assertSame(1, Mongo::collection(Collections::USERS)->countDocuments());
    }

    public function testIssue37SearchAndFiltersNarrowTheList(): void
    {
        $this->signIn('ADMIN', 'admin@escola.local');
        $this->createUser('bruno@escola.local', 'AOE', name: 'Bruno Lima');
        $this->createUser('carla@escola.local', 'SECRETARY', false, name: 'Carla Souza');

        $byName = $this->http->get('/users?q=bru')->body;
        $byProfile = $this->http->get('/users?profile=SECRETARY')->body;
        $inactive = $this->http->get('/users?status=inactive')->body;

        self::assertStringContainsString('Bruno Lima', $byName);
        self::assertStringNotContainsString('Carla Souza', $byName);
        self::assertStringContainsString('Carla Souza', $byProfile);
        self::assertStringNotContainsString('Bruno Lima', $byProfile);
        self::assertStringContainsString('Carla Souza', $inactive);
        self::assertStringNotContainsString('Bruno Lima', $inactive);
    }

    public function testAdministratorEditsAUserButCannotLockHimselfOut(): void
    {
        $this->signIn('ADMIN', 'admin@escola.local');
        $bruno = $this->createUser('bruno@escola.local', 'AOE', name: 'Bruno Lima');
        $adminId = (string)Mongo::collection(Collections::USERS)->findOne(['email_normalized' => 'admin@escola.local'])['_id'];

        $edited = $this->send('/users/' . $bruno['_id'], ['name' => 'Bruno de Lima', 'profile' => 'COORDINATION', 'active' => '1']);
        $self = $this->send('/users/' . $adminId, ['name' => 'Usuária ADMIN', 'profile' => 'ADMIN', 'active' => '0']);

        self::assertSame(303, $edited->status);
        self::assertSame('COORDINATION', Mongo::collection(Collections::USERS)->findOne(['_id' => $bruno['_id']])['profile']);
        self::assertSame(422, $self->status);
        self::assertStringContainsString('Você não pode desativar o seu próprio usuário.', $self->body);
        self::assertSame(200, $this->http->get('/home')->status, 'o administrador continua com acesso');
    }

    public function testAdministratorSetsANewPasswordForAUser(): void
    {
        $this->signIn('ADMIN', 'admin@escola.local');
        $bruno = $this->createUser('bruno@escola.local', 'AOE', name: 'Bruno Lima');

        $reset = $this->send('/users/' . $bruno['_id'] . '/password', ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);

        self::assertSame(303, $reset->status);
        $this->http->clearCookies();
        self::assertSame(401, $this->login('bruno@escola.local')->status, 'a senha antiga deixou de valer');
        self::assertSame(303, $this->login('bruno@escola.local', self::NEW_PASSWORD)->status);
    }

    public function testIssue28HomeShowsRealNumbersAccordingToTheProfile(): void
    {
        $period = $this->seedPeriod();
        $class = $this->seedClass($period['id']);
        $ana = $this->seedStudent($class['id'], 'RA-001', 'Ana Beatriz Souza');
        $this->seedStudent($class['id'], 'RA-002', 'Bruno Lima');
        $this->seedGuardian();
        $registrar = $this->createUser('registro@escola.local', 'AOE', name: 'Registro');
        $services = $this->services();
        self::assertArrayHasKey('students', $services);
        (new \Saa\Services\AttendanceService(
            new \Saa\Models\AttendanceModel(Mongo::database()),
            new \Saa\Models\StudentContextModel(Mongo::database()),
            new \Saa\Services\NotificationService(
                new \Saa\Models\EmailNotificationModel(Mongo::database()),
                new \Saa\Services\MessageTemplateService(new \Saa\Models\MessageTemplateModel(Mongo::database())),
                new \Saa\Services\Mail\MailerFactory(new \Saa\Tests\Integration\Notifications\RecordingMailer()),
                new \Saa\Services\AuditService(new \Saa\Models\AuditEventModel(Mongo::database())),
            ),
            new \Saa\Services\AuditService(new \Saa\Models\AuditEventModel(Mongo::database())),
        ))->registerAbsence($ana['id'], Clock::today(), (string)$registrar['_id'], null);

        $this->signIn('AOE', 'aoe@escola.local');
        $aoe = $this->http->get('/home')->body;
        $this->http->clearCookies();
        $this->signIn('SECRETARY', 'sec@escola.local');
        $secretary = $this->http->get('/home')->body;

        $stat = static fn (string $html, string $label): ?string => preg_match(
            '/' . preg_quote($label, '/') . '.*?stat__value[^>]*>\s*([\d.]+)/s', $html, $m) === 1 ? $m[1] : null;
        self::assertSame('2', $stat($aoe, 'Alunos ativos'));
        self::assertSame('1', $stat($aoe, 'Responsáveis ativos'));
        self::assertSame('1', $stat($aoe, 'Turmas ativas'));
        self::assertSame('1', $stat($aoe, 'Ausências hoje'));
        self::assertSame('1', $stat($aoe, 'Justificativas pendentes'));
        self::assertSame('2', $stat($secretary, 'Alunos ativos'));
        self::assertStringNotContainsString('Ausências hoje', $secretary, 'Secretária não vê ocorrências');
    }

    public function testIssue28HomeWarnsWhenThereIsNoActivePeriod(): void
    {
        $this->signIn('SECRETARY', 'sec@escola.local');

        $home = $this->http->get('/home')->body;

        self::assertStringContainsString('href="/periods"', $home);
        self::assertStringContainsString('alert--warning', $home);
    }
}
