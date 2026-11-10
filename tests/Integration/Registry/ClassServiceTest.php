<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Registry;

use MongoDB\BSON\ObjectId;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Services\ClassService;

/** Turmas (issue #40) e associação de alunos à turma (issue #41). */
final class ClassServiceTest extends RegistryTestCase
{
    public function testIssue40ClassIsCreatedWithUppercaseCodeInsideItsPeriod(): void
    {
        $period = $this->period();

        $class = $this->classes->create([
            'academic_period_id' => $period['id'], 'code' => ' 6a ', 'name' => '6º Ano A', 'grade' => '6º ano', 'shift' => 'MORNING',
        ]);

        self::assertSame('6A', $class['code']);
        self::assertSame($period['id'], $class['academic_period_id']);
        self::assertTrue($class['active']);
        self::assertCount(1, $this->audit('class.create'));
    }

    public function testIssue40ClassCodeIsUniqueOnlyInsideTheSamePeriod(): void
    {
        $p2026 = $this->period('2026');
        $p2027 = $this->period('2027', '2027-02-01', '2027-12-17');
        $this->schoolClass($p2026['id'], '6A');

        $errors = self::errorsOf(fn () => $this->schoolClass($p2026['id'], '6a'));
        $other = $this->schoolClass($p2027['id'], '6A');

        self::assertSame('Já existe uma turma com este código neste período letivo.', $errors['code']);
        self::assertSame('6A', $other['code']);
        self::assertSame(2, Mongo::collection(Collections::CLASSES)->countDocuments());
    }

    public function testIssue40ClassValidationRejectsUnknownPeriodShiftAndBlankFields(): void
    {
        $blank = self::errorsOf(fn () => $this->classes->create([]));
        self::assertSame('Informe o período letivo.', $blank['academic_period_id']);
        self::assertSame('Informe o código da turma.', $blank['code']);
        self::assertSame('Informe o turno.', $blank['shift']);

        $invalid = self::errorsOf(fn () => $this->classes->create([
            'academic_period_id' => (string)new ObjectId(), 'code' => '6A<script>', 'name' => 'X', 'grade' => '6', 'shift' => 'MADRUGADA',
        ]));
        self::assertSame('Selecione um período letivo válido.', $invalid['academic_period_id']);
        self::assertSame('Selecione uma opção válida.', $invalid['shift']);
        self::assertSame('Use letras, números, espaço, ponto ou hífen no código.', $invalid['code']);
    }

    public function testIssue40ListDefaultsToTheActivePeriodAndFiltersByShiftAndCountsStudents(): void
    {
        $p2026 = $this->period('2026');
        $p2027 = $this->period('2027', '2027-02-01', '2027-12-17');
        $morning = $this->schoolClass($p2026['id'], '6A', 'MORNING');
        $this->schoolClass($p2026['id'], '7B', 'AFTERNOON');
        $this->schoolClass($p2027['id'], '8C', 'MORNING');
        $this->student($morning['id'], 'RA-1');
        $this->student($morning['id'], 'RA-2', 'Bruno Lima');

        $default = $this->classes->list(null, null, null);
        $byShift = $this->classes->list(null, 'AFTERNOON', null);
        $all = $this->classes->list('all', null, null);

        self::assertSame(['6A', '7B'], array_column($default['items'], 'code'));
        self::assertSame($p2026['id'], $default['filters']['period']);
        self::assertSame(2, $default['items'][0]['student_count']);
        self::assertSame('2026', $default['items'][0]['period_name']);
        self::assertSame(['7B'], array_column($byShift['items'], 'code'));
        self::assertCount(3, $all['items']);
        self::assertSame('all', $all['filters']['period']);
    }

    public function testIssue40ArchiveIsRefusedWhileTheClassHasActiveEnrollments(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $b = $this->schoolClass($period['id'], '6B');
        $student = $this->student($a['id']);

        $errors = self::errorsOf(fn () => $this->classes->archive($a['id']));
        self::assertSame(ClassService::ARCHIVE_BLOCKED, $errors['class']);
        self::assertSame('Transfira os alunos antes de arquivar a turma.', ClassService::ARCHIVE_BLOCKED);
        self::assertTrue($this->classes->show($a['id'])['class']['active']);

        $this->students->changeClass($student['id'], $b['id']);
        $this->classes->archive($a['id']);

        self::assertFalse($this->classes->show($a['id'])['class']['active']);
        self::assertCount(1, $this->audit('class.archive'));
        self::assertSame(['6A'], array_column($this->classes->list(null, null, 'archived')['items'], 'code'));
        self::assertSame(1, Mongo::collection(Collections::CLASSES)->countDocuments(['code' => '6A']), 'arquivar não apaga');
    }

    public function testIssue41AssociatingAStudentFromAnotherClassEndsTheOldEnrollmentWithClassChange(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $b = $this->schoolClass($period['id'], '6B');
        $student = $this->student($a['id']);

        $result = $this->classes->enrollStudent($b['id'], $student['id']);

        self::assertTrue($result['changed']);
        $enrollments = $this->enrollmentsOf($student['id']);
        self::assertCount(2, $enrollments, 'histórico preservado: nada é apagado');
        self::assertFalse($enrollments[0]['active']);
        self::assertSame('CLASS_CHANGE', $enrollments[0]['end_reason']);
        self::assertSame('2026-05-10', \Saa\Database\Bson::ymd($enrollments[0]['end_date']));
        self::assertTrue($enrollments[1]['active']);
        self::assertSame($b['id'], (string)$enrollments[1]['class_id']);
        self::assertSame([], $this->classes->show($a['id'])['students']);
        self::assertSame([$student['id']], array_column($this->classes->show($b['id'])['students'], 'id'));
        self::assertCount(1, $this->audit('enrollment.class_change'));
    }

    public function testIssue41AssociationRejectsUnknownStudentArchivedClassAndRepeatedEnrollment(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $b = $this->schoolClass($period['id'], '6B');
        $student = $this->student($a['id']);

        self::assertSame('Selecione um aluno.', self::errorsOf(fn () => $this->classes->enrollStudent($a['id'], 'x'))['student_id']);
        self::assertSame('O aluno já está matriculado nesta turma.', self::errorsOf(fn () => $this->classes->enrollStudent($a['id'], $student['id']))['student_id']);
        $this->classes->archive($b['id']);
        self::assertSame('Esta turma está arquivada.', self::errorsOf(fn () => $this->classes->enrollStudent($b['id'], $student['id']))['student_id']);
    }

    public function testIssue40ShowFindsStudentsToAssociateExcludingThoseAlreadyInTheClass(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $b = $this->schoolClass($period['id'], '6B');
        $this->student($a['id'], 'RA-1', 'Ana Souza');
        $this->student($b['id'], 'RA-2', 'Ana Lima');

        $show = $this->classes->show($a['id'], 'ana');

        self::assertSame(['Ana Lima'], array_column((array)$show['candidates'], 'name'));
        self::assertSame('6B', $show['candidates'][0]['current_class']);
        self::assertNull($this->classes->show($a['id'])['candidates']);
    }

    public function testIssue40UpdateKeepsThePeriodAndRejectsDuplicateCode(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $this->schoolClass($period['id'], '6B');

        $updated = $this->classes->update($a['id'], ['code' => '6c', 'name' => '6º Ano C', 'grade' => '6º ano', 'shift' => 'NIGHT', 'academic_period_id' => (string)new ObjectId()]);
        $errors = self::errorsOf(fn () => $this->classes->update($a['id'], ['code' => '6B', 'name' => 'x', 'grade' => 'x', 'shift' => 'NIGHT']));

        self::assertSame('6C', $updated['code']);
        self::assertSame('NIGHT', $updated['shift']);
        self::assertSame($period['id'], $updated['academic_period_id'], 'o período não muda na edição');
        self::assertSame('Já existe uma turma com este código neste período letivo.', $errors['code']);
    }
}
