<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Database\Bson;
use Saa\Domain\EndReason;
use Saa\Domain\Shift;
use Saa\Models\AcademicPeriodModel;
use Saa\Models\EnrollmentModel;
use Saa\Models\SchoolClassModel;
use Saa\Models\StudentModel;

/**
 * Matrículas (vínculo aluno ↔ turma — issue #41, MD-02, MD-13): uma matrícula ativa por aluno; trocar de turma
 * ENCERRA a matrícula atual (end_date + CLASS_CHANGE) e cria outra — nada é apagado. Só se matricula em turma
 * ativa do período letivo vigente.
 */
final class EnrollmentService
{
    public function __construct(
        private readonly EnrollmentModel $enrollments,
        private readonly StudentModel $students,
        private readonly SchoolClassModel $classes,
        private readonly AcademicPeriodModel $periods,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Turma válida para matrícula: existe, está ativa e pertence ao período letivo vigente.
     *
     * @param string $field campo do formulário que recebe a mensagem de erro
     * @return array<string, mixed> documento da turma
     * @throws ValidationException
     */
    public function enrollableClass(?string $classId, string $field = 'class_id'): array
    {
        $error = $this->classError($classId);
        if ($error !== null) {
            throw new ValidationException([$field => $error]);
        }
        return (array)$this->classes->findById($classId);
    }

    /** Mensagem de erro da turma escolhida para matrícula, ou null se ela for válida. */
    public function classError(?string $classId): ?string
    {
        $oid = Bson::objectId($classId);
        if ($oid === null) {
            return 'Selecione a turma.';
        }
        $class = $this->classes->findById($oid);
        if ($class === null) {
            return 'Selecione uma turma válida.';
        }
        if (($class['active'] ?? false) !== true) {
            return 'Esta turma está arquivada.';
        }
        $period = $this->periods->findById($class['academic_period_id'] ?? null);
        if ($period === null || ($period['active'] ?? false) !== true) {
            return 'A turma precisa ser do período letivo ativo.';
        }
        return null;
    }

    /**
     * Matricula o aluno na turma. Se ele já tiver matrícula ativa em outra turma, ela é encerrada com CLASS_CHANGE
     * e uma nova é criada (histórico preservado).
     *
     * @param string $field campo do formulário que recebe as mensagens de erro
     * @return array{enrollment: array<string, mixed>, changed: bool}
     * @throws NotFoundException aluno inexistente
     * @throws ValidationException turma inválida, aluno arquivado ou já matriculado na turma
     * @throws ConflictException alteração simultânea da matrícula
     */
    public function assign(ObjectId $studentId, ?string $classId, string $field = 'class_id'): array
    {
        $student = $this->students->findById($studentId) ?? throw new NotFoundException('Aluno não encontrado.');
        if (($student['active'] ?? false) !== true) {
            throw new ValidationException([$field => 'Reative o cadastro do aluno antes de matriculá-lo em uma turma.']);
        }
        $class = $this->enrollableClass($classId, $field);
        $period = (array)$this->periods->findById($class['academic_period_id']);
        $current = $this->enrollments->findActiveByStudent($studentId);
        if ($current !== null && (string)$current['class_id'] === (string)$class['_id']) {
            throw new ValidationException([$field => 'O aluno já está matriculado nesta turma.']);
        }

        $today = RegistrySupport::today();
        $start = max($today, (string)Bson::ymd($period['start_date'] ?? null));

        if ($current === null) {
            $enrollment = $this->enrollments->insertActive($studentId, $class['_id'], $class['academic_period_id'], Bson::day($start));
            $this->audit->record('enrollment.create', 'enrollments', $enrollment['_id'], [
                'student_id' => (string)$studentId,
                'class_id' => (string)$class['_id'],
            ]);
            return ['enrollment' => $enrollment, 'changed' => false];
        }

        $end = max($today, (string)Bson::ymd($current['start_date'] ?? null));
        if (!$this->enrollments->end($current['_id'], Bson::day($end), EndReason::CLASS_CHANGE->value)) {
            throw new ConflictException('A matrícula do aluno foi alterada por outra pessoa. Recarregue a página e tente novamente.');
        }
        try {
            $enrollment = $this->enrollments->insertActive($studentId, $class['_id'], $class['academic_period_id'], Bson::day(max($start, $end)));
        } catch (ConflictException $conflict) {
            $this->enrollments->reopen($current['_id']);
            throw $conflict;
        }
        $this->audit->record('enrollment.class_change', 'enrollments', $enrollment['_id'], [
            'student_id' => (string)$studentId,
            'from_class_id' => (string)$current['class_id'],
            'to_class_id' => (string)$class['_id'],
            'ended_enrollment_id' => (string)$current['_id'],
        ]);
        return ['enrollment' => $enrollment, 'changed' => true];
    }

    /**
     * Encerra a matrícula ativa do aluno (por exemplo, ao arquivar o cadastro).
     *
     * @return bool true se havia matrícula ativa
     */
    public function endActive(ObjectId $studentId, EndReason $reason): bool
    {
        $current = $this->enrollments->findActiveByStudent($studentId);
        if ($current === null) {
            return false;
        }
        $end = max(RegistrySupport::today(), (string)Bson::ymd($current['start_date'] ?? null));
        if (!$this->enrollments->end($current['_id'], Bson::day($end), $reason->value)) {
            return false;
        }
        $this->audit->record('enrollment.end', 'enrollments', $current['_id'], [
            'student_id' => (string)$studentId,
            'class_id' => (string)$current['class_id'],
            'reason' => $reason->value,
        ]);
        return true;
    }

    /**
     * Histórico de matrículas do aluno (mais recente primeiro), pronto para exibição.
     *
     * @return list<array<string, mixed>>
     */
    public function history(ObjectId $studentId): array
    {
        $history = [];
        foreach ($this->enrollments->historyOfStudent($studentId) as $item) {
            $history[] = [
                'id' => (string)$item['_id'],
                'class_id' => (string)$item['class_id'],
                'class_code' => (string)($item['class']['code'] ?? '—'),
                'class_name' => (string)($item['class']['name'] ?? 'Turma removida'),
                'period_name' => (string)($item['period']['name'] ?? '—'),
                'start_date' => Bson::ymd($item['start_date'] ?? null),
                'end_date' => Bson::ymd($item['end_date'] ?? null),
                'end_reason' => $item['end_reason'] ?? null,
                'end_reason_label' => ($item['end_reason'] ?? null) === null ? null : EndReason::labelFor($item['end_reason']),
                'active' => ($item['active'] ?? false) === true,
            ];
        }
        return $history;
    }

    /**
     * Turmas ativas do período vigente para o seletor de matrícula: id => "6A — 6º Ano A (Manhã)".
     *
     * @return array<string, string>
     */
    public function classOptions(): array
    {
        $period = $this->periods->findActive();
        if ($period === null) {
            return [];
        }
        $options = [];
        foreach ($this->classes->activeOfPeriod($period['_id']) as $class) {
            $options[(string)$class['_id']] = sprintf(
                '%s — %s (%s)',
                (string)$class['code'],
                (string)$class['name'],
                Shift::labelFor($class['shift'] ?? null)
            );
        }
        return $options;
    }
}
