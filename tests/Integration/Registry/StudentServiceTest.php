<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Registry;

use Saa\Core\Clock;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Mongo;

/** Alunos (issue #38) e matrícula/troca de turma (issue #41). */
final class StudentServiceTest extends RegistryTestCase
{
    public function testIssue38CreatingAStudentAlsoEnrollsHimInTheChosenClass(): void
    {
        $class = $this->schoolClass($this->period()['id']);

        $student = $this->student($class['id']);

        self::assertSame('RA-001', $student['student_code']);
        self::assertSame('2015-03-14', $student['birth_date']);
        self::assertTrue($student['active']);
        $enrollments = $this->enrollmentsOf($student['id']);
        self::assertCount(1, $enrollments);
        self::assertTrue($enrollments[0]['active']);
        self::assertSame($class['id'], (string)$enrollments[0]['class_id']);
        self::assertSame('2026-05-10', Bson::ymd($enrollments[0]['start_date']));
        $stored = Mongo::collection(Collections::STUDENTS)->findOne(['student_code' => 'RA-001']);
        self::assertSame([], $stored['guardians']);
        self::assertCount(1, $this->audit('student.create'));
        self::assertCount(1, $this->audit('enrollment.create'));
    }

    public function testIssue38StudentValidationCoversRequiredFieldsBirthDateAndClass(): void
    {
        $class = $this->schoolClass($this->period()['id']);

        $blank = self::errorsOf(fn () => $this->students->create([]));
        self::assertSame('Informe o nome do aluno.', $blank['name']);
        self::assertSame('Informe a matrícula escolar.', $blank['student_code']);
        self::assertSame('Informe a data de nascimento.', $blank['birth_date']);
        self::assertSame('Informe a turma.', $blank['class_id']);

        $future = self::errorsOf(fn () => $this->students->create(['name' => 'Ana', 'student_code' => 'RA-1', 'birth_date' => '2026-05-10', 'class_id' => $class['id']]));
        self::assertSame('A data de nascimento precisa ser anterior a hoje.', $future['birth_date']);

        $weird = self::errorsOf(fn () => $this->students->create(['name' => 'A', 'student_code' => str_repeat('9', 31), 'birth_date' => '14/03/2015', 'class_id' => 'abc']));
        self::assertSame('Use pelo menos 2 caracteres.', $weird['name']);
        self::assertSame('Use no máximo 30 caracteres.', $weird['student_code']);
        self::assertSame('Informe uma data válida.', $weird['birth_date']);
        self::assertSame('Selecione a turma.', $weird['class_id']);
        self::assertSame(0, Mongo::collection(Collections::STUDENTS)->countDocuments());
    }

    public function testIssue38StudentMustJoinAClassOfTheActivePeriod(): void
    {
        $this->period('2026');
        $next = $this->period('2027', '2027-02-01', '2027-12-17');
        $future = $this->schoolClass($next['id'], '6A');

        $errors = self::errorsOf(fn () => $this->student($future['id']));

        self::assertSame('A turma precisa ser do período letivo ativo.', $errors['class_id']);
    }

    public function testIssue38DuplicateStudentCodeIsAFormErrorOnCreateAndUpdate(): void
    {
        $class = $this->schoolClass($this->period()['id']);
        $this->student($class['id'], 'RA-001');
        $other = $this->student($class['id'], 'RA-002', 'Bruno Lima');

        $onCreate = self::errorsOf(fn () => $this->student($class['id'], 'RA-001', 'Outra Pessoa'));
        $onUpdate = self::errorsOf(fn () => $this->students->update($other['id'], ['name' => 'Bruno Lima', 'student_code' => 'RA-001', 'birth_date' => '2015-01-01']));

        self::assertSame('Já existe um aluno com esta matrícula escolar.', $onCreate['student_code']);
        self::assertSame('Já existe um aluno com esta matrícula escolar.', $onUpdate['student_code']);
        self::assertSame(2, Mongo::collection(Collections::STUDENTS)->countDocuments());
    }

    public function testIssue38StudentCodeIsNormalizedSoCaseAndSpacesCannotDuplicateIt(): void
    {
        $class = $this->schoolClass($this->period()['id']);
        $created = $this->student($class['id'], '  ra-2026   0001 ');
        $other = $this->student($class['id'], 'RA-2026-0002', 'Bruno Lima');

        self::assertSame('RA-2026 0001', $created['student_code'], 'gravado em maiúsculas e com espaços simples');
        foreach (['RA-2026 0001', 'ra-2026 0001', 'Ra-2026    0001'] as $variant) {
            $errors = self::errorsOf(fn () => $this->student($class['id'], $variant, 'Outra Pessoa'));
            self::assertSame('Já existe um aluno com esta matrícula escolar.', $errors['student_code'], $variant);
        }
        $onUpdate = self::errorsOf(fn () => $this->students->update($other['id'], ['name' => 'Bruno Lima', 'student_code' => 'ra-2026 0001', 'birth_date' => '2015-01-01']));
        self::assertSame('Já existe um aluno com esta matrícula escolar.', $onUpdate['student_code']);
        self::assertSame(2, Mongo::collection(Collections::STUDENTS)->countDocuments());
    }

    public function testIssue38ListSearchesByNameOrCodeFiltersByClassAndShowsTheCurrentClass(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $b = $this->schoolClass($period['id'], '6B');
        $this->student($a['id'], 'RA-001', 'Ana Beatriz Souza');
        $this->student($b['id'], 'RA-002', 'Bruno Lima');
        $this->student($b['id'], 'X.(9)*', 'Carla Dias');

        $byName = $this->students->list('beatriz', null, null, 1);
        $byCode = $this->students->list('ra-00', null, null, 1);
        $regexChars = $this->students->list('.(9)*', null, null, 1);
        $byClass = $this->students->list(null, $b['id'], null, 1);

        self::assertSame(['Ana Beatriz Souza'], array_column($byName['items'], 'name'));
        self::assertSame('6A', $byName['items'][0]['class']['code']);
        self::assertSame(['Ana Beatriz Souza', 'Bruno Lima'], array_column($byCode['items'], 'name'));
        self::assertSame(['Carla Dias'], array_column($regexChars['items'], 'name'), 'o termo é escapado: nada de regex do usuário');
        self::assertSame(['Bruno Lima', 'Carla Dias'], array_column($byClass['items'], 'name'));
        self::assertSame($b['id'], $byClass['filters']['class']);
    }

    public function testIssue38ListIsPaginatedWithTwentyPerPage(): void
    {
        $class = $this->schoolClass($this->period()['id']);
        for ($i = 1; $i <= 23; $i++) {
            $this->student($class['id'], sprintf('RA-%03d', $i), sprintf('Aluno %02d', $i));
        }

        $first = $this->students->list(null, null, null, 1);
        $second = $this->students->list(null, null, null, 2);
        $beyond = $this->students->list(null, null, null, 99);

        self::assertCount(20, $first['items']);
        self::assertSame(23, $first['pagination']['total']);
        self::assertSame(2, $first['pagination']['pages']);
        self::assertSame(1, $first['pagination']['page']);
        self::assertSame(['Aluno 21', 'Aluno 22', 'Aluno 23'], array_column($second['items'], 'name'));
        self::assertSame(2, $beyond['pagination']['page'], 'página além do fim mostra a última');
        self::assertCount(3, $beyond['items']);
    }

    public function testIssue41ChangingClassPreservesTheEnrollmentHistory(): void
    {
        $period = $this->period();
        $a = $this->schoolClass($period['id'], '6A');
        $b = $this->schoolClass($period['id'], '6B');
        $student = $this->student($a['id']);

        Clock::fix('2026-06-01T12:00:00Z');
        self::assertTrue($this->students->changeClass($student['id'], $b['id']));

        $history = $this->students->show($student['id'])['history'];
        self::assertCount(2, $history);
        self::assertSame(['6B', '6A'], array_column($history, 'class_code'), 'mais recente primeiro');
        self::assertTrue($history[0]['active']);
        self::assertSame('2026-06-01', $history[0]['start_date']);
        self::assertFalse($history[1]['active']);
        self::assertSame('2026-06-01', $history[1]['end_date']);
        self::assertSame('CLASS_CHANGE', $history[1]['end_reason']);
        self::assertSame('Mudança de turma', $history[1]['end_reason_label']);
        self::assertSame('6B', $this->students->show($student['id'])['student']['class']['code']);
        self::assertSame(1, Mongo::collection(Collections::ENROLLMENTS)->countDocuments(['active' => true]));

        $same = self::errorsOf(fn () => $this->students->changeClass($student['id'], $b['id']));
        self::assertSame('O aluno já está matriculado nesta turma.', $same['class_id']);
    }

    public function testIssue38ArchivingEndsTheEnrollmentAndReactivatingKeepsTheHistory(): void
    {
        $class = $this->schoolClass($this->period()['id']);
        $student = $this->student($class['id']);

        $this->students->archive($student['id']);

        self::assertSame([], $this->students->list(null, null, null, 1)['items']);
        self::assertSame(['RA-001'], array_column($this->students->list(null, null, 'archived', 1)['items'], 'student_code'));
        $enrollment = $this->enrollmentsOf($student['id'])[0];
        self::assertFalse($enrollment['active']);
        self::assertSame('TRANSFER', $enrollment['end_reason']);
        self::assertSame(
            'Reative o cadastro do aluno antes de matriculá-lo em uma turma.',
            self::errorsOf(fn () => $this->students->changeClass($student['id'], $class['id']))['class_id']
        );
        self::assertCount(1, $this->audit('student.archive'));

        $this->students->reactivate($student['id']);
        $show = $this->students->show($student['id']);
        self::assertTrue($show['student']['active']);
        self::assertNull($show['student']['class'], 'reativado fica sem turma até ser matriculado de novo');
        self::assertCount(1, $show['history']);
        self::assertFalse($this->students->changeClass($student['id'], $class['id']), 'primeira matrícula após reativar não é troca');
        self::assertCount(2, $this->enrollmentsOf($student['id']));
        self::assertSame(1, Mongo::collection(Collections::STUDENTS)->countDocuments(), 'nada é apagado');
    }

    public function testIssue38UpdateChangesTheBasicData(): void
    {
        $class = $this->schoolClass($this->period()['id']);
        $student = $this->student($class['id']);

        $updated = $this->students->update($student['id'], ['name' => 'Ana B. Souza', 'student_code' => 'RA-900', 'birth_date' => '2014-12-31']);

        self::assertSame('Ana B. Souza', $updated['name']);
        self::assertSame('RA-900', $updated['student_code']);
        self::assertSame('2014-12-31', $updated['birth_date']);
        self::assertCount(1, $this->audit('student.update'));
    }

    public function testIssue38UnknownStudentIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->students->show('507f1f77bcf86cd799439011');
    }
}
