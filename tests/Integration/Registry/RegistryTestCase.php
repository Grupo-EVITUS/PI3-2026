<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Registry;

use Saa\Core\Clock;
use Saa\Core\Exceptions\ValidationException;
use Saa\Database\Collections;
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
use Saa\Tests\Support\MongoTestCase;

/**
 * Base dos testes de integração dos cadastros (issues #38, #39, #40, #41): monta os serviços reais contra o banco
 * de teste, fixa o relógio em 10/05/2026 e oferece atalhos para criar período, turma, aluno e responsável.
 */
abstract class RegistryTestCase extends MongoTestCase
{
    protected AcademicPeriodService $periods;
    protected ClassService $classes;
    protected StudentService $students;
    protected GuardianService $guardians;
    protected GuardianLinkService $links;
    protected EnrollmentService $enrollments;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::fix('2026-05-10T12:00:00Z');
        $db = $this->db();
        $audit = new AuditService(new AuditEventModel($db));
        $periodModel = new AcademicPeriodModel($db);
        $classModel = new SchoolClassModel($db);
        $studentModel = new StudentModel($db);
        $guardianModel = new GuardianModel($db);
        $enrollmentModel = new EnrollmentModel($db);

        $this->periods = new AcademicPeriodService($periodModel, $audit);
        $this->enrollments = new EnrollmentService($enrollmentModel, $studentModel, $classModel, $periodModel, $audit);
        $this->classes = new ClassService($classModel, $periodModel, $enrollmentModel, $studentModel, $this->enrollments, $audit);
        $this->guardians = new GuardianService($guardianModel, $studentModel, $audit);
        $this->links = new GuardianLinkService($studentModel, $guardianModel, $this->guardians, $audit);
        $this->students = new StudentService($studentModel, $this->enrollments, $this->links, $this->guardians, $audit);
    }

    /** @return array<string, mixed> */
    protected function period(string $name = '2026', string $start = '2026-02-02', string $end = '2026-12-18'): array
    {
        return $this->periods->create([
            'name' => $name, 'start_date' => $start, 'end_date' => $end,
            'min_attendance_percent' => '75', 'alert_below_percent' => '80',
        ]);
    }

    /** @return array<string, mixed> */
    protected function schoolClass(string $periodId, string $code = '6A', string $shift = 'MORNING'): array
    {
        return $this->classes->create([
            'academic_period_id' => $periodId, 'code' => $code, 'name' => 'Turma ' . $code, 'grade' => '6º ano', 'shift' => $shift,
        ]);
    }

    /** @return array<string, mixed> */
    protected function student(string $classId, string $code = 'RA-001', string $name = 'Ana Beatriz Souza'): array
    {
        return $this->students->create(['name' => $name, 'student_code' => $code, 'birth_date' => '2015-03-14', 'class_id' => $classId]);
    }

    /** @return array<string, mixed> */
    protected function guardian(string $email = 'carlos@exemplo.com', string $name = 'Carlos Souza'): array
    {
        return $this->links->createGuardian(['name' => $name, 'email' => $email, 'phone' => '(11) 99999-0001'])['guardian'];
    }

    /** @return array<string, string> erros de validação lançados pela função */
    protected static function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $error) {
            return $error->errors();
        }
        self::fail('Era esperada uma ValidationException.');
    }

    /** @return list<array<string, mixed>> */
    protected function audit(string $action): array
    {
        return Mongo::collection(Collections::AUDIT_EVENTS)->find(['action' => $action])->toArray();
    }

    /** @return list<array<string, mixed>> */
    protected function enrollmentsOf(string $studentId): array
    {
        return Mongo::collection(Collections::ENROLLMENTS)
            ->find(['student_id' => new \MongoDB\BSON\ObjectId($studentId)], ['sort' => ['created_at' => 1, '_id' => 1]])
            ->toArray();
    }
}
