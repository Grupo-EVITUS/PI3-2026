<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Collections;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\JustificationStatus;

/**
 * Contagens da tela inicial (issue #28). Cada número é UMA consulta countDocuments apoiada em índice — nada de
 * percorrer listas no PHP nem consultar item a item (sem N+1):
 *   alunos ativos         students            {active: true}                         ix_students_active_name
 *   responsáveis ativos   guardians           {active: true}                         ix_guardians_active_name
 *   turmas ativas         classes             {academic_period_id, active: true}     ix_classes_period_shift
 *   ausências de hoje     attendance_records  {date: hoje, status: ABSENT|EXCUSED}   ix_attendance_date_status
 *   pendentes             attendance_records  {justification.status: PENDING}        ix_attendance_justification_status_date
 */
final class HomeStatsModel extends Model
{
    protected const COLLECTION = Collections::ATTENDANCE_RECORDS;

    /**
     * @param ObjectId|null $activePeriodId período vigente (sem período, não há turmas ativas a contar)
     * @return array{students: int, guardians: int, classes: int}
     */
    public function registryCounts(?ObjectId $activePeriodId): array
    {
        return [
            'students' => $this->database->selectCollection(Collections::STUDENTS)->countDocuments(['active' => true]),
            'guardians' => $this->database->selectCollection(Collections::GUARDIANS)->countDocuments(['active' => true]),
            'classes' => $activePeriodId === null ? 0 : $this->database->selectCollection(Collections::CLASSES)
                ->countDocuments(['academic_period_id' => $activePeriodId, 'active' => true]),
        ];
    }

    /**
     * @param UTCDateTime $today meia-noite UTC do dia de hoje no fuso escolar (Bson::day(Clock::today()))
     * @return array{absences_today: int, pending: int}
     */
    public function occurrenceCounts(UTCDateTime $today): array
    {
        return [
            'absences_today' => $this->collection()->countDocuments([
                'date' => $today,
                'status' => ['$in' => AttendanceStatus::absenceValues()],
            ]),
            'pending' => $this->collection()->countDocuments([
                'justification.status' => JustificationStatus::PENDING->value,
            ]),
        ];
    }
}
