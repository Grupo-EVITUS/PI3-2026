<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Registry;

use Saa\Core\Exceptions\NotFoundException;
use Saa\Database\Collections;
use Saa\Database\Mongo;

/** Períodos letivos (base da issue #40): cadastro, regra de frequência, edição e um único período ativo (MD-12, MD-14). */
final class AcademicPeriodServiceTest extends RegistryTestCase
{
    public function testIssue40FirstPeriodIsCreatedActiveAndTheNextOnesInactive(): void
    {
        $first = $this->period('2026');
        $second = $this->period('2027', '2027-02-01', '2027-12-17');

        self::assertTrue($first['active']);
        self::assertFalse($second['active']);
        self::assertSame(75, $first['min_attendance_percent']);
        self::assertSame(80, $first['alert_below_percent']);
        self::assertSame('2026-02-02', $first['start_date']);
        self::assertSame($first['id'], $this->periods->active()['id'] ?? null);
        self::assertCount(2, $this->audit('period.create'));
    }

    public function testIssue40PeriodValidationExplainsEachField(): void
    {
        $blank = self::errorsOf(fn () => $this->periods->create([]));
        self::assertSame('Informe o nome do período.', $blank['name']);
        self::assertSame('Informe a data de início.', $blank['start_date']);
        self::assertSame('Informe a frequência mínima.', $blank['min_attendance_percent']);

        $wrong = self::errorsOf(fn () => $this->periods->create([
            'name' => '2026', 'start_date' => '2026-12-18', 'end_date' => '2026-02-02',
            'min_attendance_percent' => '80', 'alert_below_percent' => '70',
        ]));
        self::assertSame('A data de término deve ser depois da data de início.', $wrong['end_date']);
        self::assertSame('O limite de alerta não pode ser menor que a frequência mínima.', $wrong['alert_below_percent']);

        $range = self::errorsOf(fn () => $this->periods->create([
            'name' => '2026', 'start_date' => '2026-02-31', 'end_date' => '2026-12-18',
            'min_attendance_percent' => '101', 'alert_below_percent' => 'x',
        ]));
        self::assertSame('Informe uma data válida.', $range['start_date']);
        self::assertSame('Informe um valor menor ou igual a 100.', $range['min_attendance_percent']);
        self::assertSame('Informe um número inteiro.', $range['alert_below_percent']);
        self::assertSame(0, Mongo::collection(Collections::ACADEMIC_PERIODS)->countDocuments());
    }

    public function testIssue40DuplicatePeriodNameIsAFormErrorNotAServerError(): void
    {
        $this->period('2026');

        $errors = self::errorsOf(fn () => $this->period('2026', '2026-03-01', '2026-11-30'));

        self::assertSame('Já existe um período letivo com este nome.', $errors['name']);
        self::assertSame(1, Mongo::collection(Collections::ACADEMIC_PERIODS)->countDocuments());
    }

    public function testIssue40ActivatingAPeriodKeepsExactlyOneActive(): void
    {
        $first = $this->period('2026');
        $second = $this->period('2027', '2027-02-01', '2027-12-17');

        $activated = $this->periods->activate($second['id']);
        $this->periods->activate($second['id']); // idempotente

        self::assertTrue($activated['active']);
        self::assertSame(1, Mongo::collection(Collections::ACADEMIC_PERIODS)->countDocuments(['active' => true]));
        self::assertFalse($this->periods->get($first['id'])['active']);
        self::assertCount(1, $this->audit('period.activate'));
    }

    public function testIssue40UpdateChangesDatesAndAttendancePolicy(): void
    {
        $period = $this->period('2026');

        $updated = $this->periods->update($period['id'], [
            'name' => '2026 (letivo)', 'start_date' => '2026-02-09', 'end_date' => '2026-12-11',
            'min_attendance_percent' => '70', 'alert_below_percent' => '85',
        ]);

        self::assertSame('2026 (letivo)', $updated['name']);
        self::assertSame('2026-02-09', $updated['start_date']);
        self::assertSame(70, $updated['min_attendance_percent']);
        self::assertSame(85, $updated['alert_below_percent']);
        self::assertTrue($updated['active'], 'editar não muda o período ativo');
        self::assertCount(1, $this->audit('period.update'));
    }

    public function testIssue40UnknownOrMalformedPeriodIdIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->periods->get('not-an-id');
    }
}
