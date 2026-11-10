<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Registry;

use PHPUnit\Framework\Attributes\DataProvider;
use Saa\Core\Router;
use Saa\Core\Config;
use Saa\Security\Permissions;

/**
 * Permissões dos cadastros (issues #38, #39, #40, #41): SECRETARY e AOE consultam e alteram; COORDINATION só consulta
 * (todo POST dele é 403); sem sessão, a tela manda para o login.
 */
final class RegistryPermissionsTest extends RegistryHttpTestCase
{
    public function testIssues38To41EveryRegistryRouteDeclaresViewForGetAndEditForPost(): void
    {
        $router = new Router();
        (require Config::basePath('config/routes/registry.php'))($router);

        self::assertNotEmpty($router->routes());
        foreach ($router->routes() as $route) {
            $expected = $route->method === 'GET' ? Permissions::REGISTRY_VIEW : Permissions::REGISTRY_EDIT;
            self::assertSame($expected, $route->permission, $route->method . ' ' . $route->path);
            self::assertSame('auth', $route->access);
            self::assertSame($route->method !== 'GET', $route->csrf);
        }
    }

    public function testIssues38To41AnonymousVisitorIsSentToTheLogin(): void
    {
        foreach (['/students', '/guardians', '/classes', '/periods'] as $path) {
            $response = $this->http->get($path);
            self::assertSame(303, $response->status, $path);
            self::assertStringStartsWith('/login', (string)$response->location());
        }
    }

    public function testIssues38To41CoordinationCanConsultButEveryWriteIsForbidden(): void
    {
        $period = $this->seedPeriod();
        $class = $this->seedClass($period['id']);
        $student = $this->seedStudent($class['id']);
        $guardian = $this->seedGuardian();
        $this->services()['links']->linkExisting($student['id'], ['guardian_id' => $guardian['id'], 'relationship' => 'PAI']);
        $this->signIn('COORDINATION', 'coord@escola.local');

        $pages = ['/students', '/students/' . $student['id'], '/guardians', '/guardians/' . $guardian['id'], '/classes', '/classes/' . $class['id'], '/periods', '/periods/' . $period['id']];
        foreach ($pages as $path) {
            $page = $this->http->get($path);
            self::assertSame(200, $page->status, $path);
            self::assertStringNotContainsString('Cadastrar aluno', $page->body, $path);
            self::assertStringNotContainsString('Salvar alterações', $page->body, $path);
            self::assertStringNotContainsString('Arquivar', $page->body, $path);
        }
        self::assertStringContainsString('Ana Beatriz Souza', $this->http->get('/students')->text());

        $writes = [
            '/students' => ['name' => 'X', 'student_code' => 'RA-9', 'birth_date' => '2015-01-01', 'class_id' => $class['id']],
            '/students/' . $student['id'] => ['name' => 'Outro nome', 'student_code' => 'RA-001', 'birth_date' => '2015-03-14'],
            '/students/' . $student['id'] . '/archive' => [],
            '/students/' . $student['id'] . '/reactivate' => [],
            '/students/' . $student['id'] . '/guardians' => ['mode' => 'existing', 'guardian_id' => $guardian['id'], 'relationship' => 'PAI'],
            '/students/' . $student['id'] . '/guardians/' . $guardian['id'] => ['relationship' => 'MAE'],
            '/students/' . $student['id'] . '/guardians/' . $guardian['id'] . '/remove' => [],
            '/students/' . $student['id'] . '/enrollment' => ['class_id' => $class['id']],
            '/guardians' => ['name' => 'Y', 'email' => 'y@exemplo.com', 'phone' => '(11) 3333-4444'],
            '/guardians/' . $guardian['id'] => ['name' => 'Z', 'email' => 'z@exemplo.com', 'phone' => '(11) 3333-4444', 'email_status' => 'VALID'],
            '/guardians/' . $guardian['id'] . '/archive' => [],
            '/guardians/' . $guardian['id'] . '/reactivate' => [],
            '/classes' => ['academic_period_id' => $period['id'], 'code' => '7B', 'name' => '7B', 'grade' => '7', 'shift' => 'NIGHT'],
            '/classes/' . $class['id'] => ['code' => '6Z', 'name' => 'x', 'grade' => 'x', 'shift' => 'NIGHT'],
            '/classes/' . $class['id'] . '/students' => ['student_id' => $student['id']],
            '/classes/' . $class['id'] . '/archive' => [],
            '/periods' => ['name' => '2099', 'start_date' => '2099-02-01', 'end_date' => '2099-12-01', 'min_attendance_percent' => '75', 'alert_below_percent' => '80'],
            '/periods/' . $period['id'] => ['name' => 'Outro', 'start_date' => '2026-02-01', 'end_date' => '2026-12-01', 'min_attendance_percent' => '75', 'alert_below_percent' => '80'],
            '/periods/' . $period['id'] . '/activate' => [],
        ];
        foreach ($writes as $path => $fields) {
            self::assertSame(403, $this->send($path, $fields)->status, 'POST ' . $path);
        }

        $show = $this->http->get('/students/' . $student['id'])->text();
        self::assertStringContainsString('Ana Beatriz Souza', $show, 'nada foi alterado');
        self::assertStringContainsString('Carlos Souza', $show);
        self::assertStringNotContainsString('Outro nome', $show);
    }

    /** @return iterable<string, array{string}> */
    public static function editors(): iterable
    {
        yield 'Secretária' => ['SECRETARY'];
        yield 'AOE' => ['AOE'];
        yield 'Administrador' => ['ADMIN'];
    }

    #[DataProvider('editors')]
    public function testIssue38EditorProfilesCanCreateAndChangeRegistries(string $profile): void
    {
        $period = $this->seedPeriod();
        $class = $this->seedClass($period['id']);
        $this->signIn($profile, strtolower($profile) . '@escola.local');

        $page = $this->http->get('/students');
        self::assertStringContainsString('Cadastrar aluno', $page->text());
        $created = $this->send('/students', ['name' => 'Davi Rocha', 'student_code' => 'RA-777', 'birth_date' => '2014-08-20', 'class_id' => $class['id']]);
        self::assertSame(303, $created->status, $created->text());
        $id = self::idFromLocation($created);

        $guardian = $this->send('/students/' . $id . '/guardians', [
            'mode' => 'new', 'guardian_name' => 'Rita Rocha', 'guardian_email' => 'rita.' . strtolower($profile) . '@exemplo.com',
            'guardian_phone' => '(11) 98888-1234', 'relationship' => 'MAE', 'receives_absence_notifications' => '1',
        ]);
        self::assertSame(303, $guardian->status, $guardian->text());
        self::assertStringContainsString('Rita Rocha', $this->http->get('/students/' . $id)->text());
    }

    public function testIssue40PeriodsMenuItemAppearsForRegistryProfiles(): void
    {
        $this->signIn('SECRETARY');

        $home = $this->http->get('/home');

        self::assertStringContainsString('href="/periods"', $home->body);
        self::assertStringContainsString('Períodos letivos', $home->text());
        self::assertStringContainsString('href="/students"', $home->body);
    }
}
