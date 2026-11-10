<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Registry;

use Saa\Database\Mongo;
use Saa\Models\AcademicPeriodModel;
use Saa\Models\AuditEventModel;
use Saa\Models\EnrollmentModel;
use Saa\Models\GuardianModel;
use Saa\Models\SchoolClassModel;
use Saa\Models\StudentModel;
use Saa\Services\AcademicPeriodService;
use Saa\Services\AuditService;
use Saa\Services\ClassService;
use Saa\Services\EnrollmentService;
use Saa\Services\GuardianLinkService;
use Saa\Services\GuardianService;
use Saa\Services\StudentService;
use Saa\Tests\Support\HttpResponse;
use Saa\Tests\Support\HttpTestCase;

/**
 * Base dos testes de aceitação dos cadastros (issues #38–#41): login por perfil, POST com token CSRF e atalhos que
 * gravam dados de apoio direto pelos serviços (mesmo banco do servidor embutido).
 */
abstract class RegistryHttpTestCase extends HttpTestCase
{
    private string $token = '';

    /** Cria o usuário do perfil, entra no sistema e guarda o token CSRF da sessão. */
    protected function signIn(string $profile = 'SECRETARY', string $email = 'secretaria@escola.local'): void
    {
        $this->createUser($email, $profile, name: 'Usuária ' . $profile);
        $login = $this->login($email);
        self::assertSame(303, $login->status, 'login de ' . $profile);
        $this->token = $this->csrfToken($this->http->get('/me'));
    }

    /** @param array<string, mixed> $fields */
    protected function send(string $path, array $fields = []): HttpResponse
    {
        return $this->http->post($path, ['csrf_token' => $this->token] + $fields);
    }

    protected function services(): array
    {
        $db = Mongo::database();
        $audit = new AuditService(new AuditEventModel($db));
        $periods = new AcademicPeriodModel($db);
        $classes = new SchoolClassModel($db);
        $students = new StudentModel($db);
        $guardians = new GuardianModel($db);
        $enrollments = new EnrollmentModel($db);
        $enrollmentService = new EnrollmentService($enrollments, $students, $classes, $periods, $audit);
        $guardianService = new GuardianService($guardians, $students, $audit);
        $links = new GuardianLinkService($students, $guardians, $guardianService, $audit);
        return [
            'periods' => new AcademicPeriodService($periods, $audit),
            'classes' => new ClassService($classes, $periods, $enrollments, $students, $enrollmentService, $audit),
            'students' => new StudentService($students, $enrollmentService, $links, $guardianService, $audit),
            'guardians' => $guardianService,
            'links' => $links,
        ];
    }

    /** @return array<string, mixed> período ativo do ano corrente */
    protected function seedPeriod(): array
    {
        $year = (int)date('Y');
        return $this->services()['periods']->create([
            'name' => (string)$year, 'start_date' => $year . '-01-01', 'end_date' => $year . '-12-31',
            'min_attendance_percent' => '75', 'alert_below_percent' => '80',
        ]);
    }

    /** @return array<string, mixed> */
    protected function seedClass(string $periodId, string $code = '6A'): array
    {
        return $this->services()['classes']->create([
            'academic_period_id' => $periodId, 'code' => $code, 'name' => 'Turma ' . $code, 'grade' => '6º ano', 'shift' => 'MORNING',
        ]);
    }

    /** @return array<string, mixed> */
    protected function seedStudent(string $classId, string $code = 'RA-001', string $name = 'Ana Beatriz Souza'): array
    {
        return $this->services()['students']->create(['name' => $name, 'student_code' => $code, 'birth_date' => '2015-03-14', 'class_id' => $classId]);
    }

    /** @return array<string, mixed> */
    protected function seedGuardian(string $email = 'carlos@exemplo.com', string $name = 'Carlos Souza'): array
    {
        return $this->services()['links']->createGuardian(['name' => $name, 'email' => $email, 'phone' => '(11) 99999-0001'])['guardian'];
    }

    /** Id hex do último segmento do Location (ex.: /students/<id>). */
    protected static function idFromLocation(HttpResponse $response): string
    {
        $location = (string)$response->location();
        self::assertMatchesRegularExpression('#/[a-f0-9]{24}$#', $location, 'Location: ' . $location);
        return substr($location, -24);
    }
}
