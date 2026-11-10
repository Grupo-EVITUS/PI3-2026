<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use Saa\Database\Collections;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\EmailStatus;

/**
 * Leituras de contexto do aluno usadas no registro de ausência (docs/MODELAGEM_MONGODB.md, seção 6):
 *
 *  - find():             CONSULTA 1 — uma agregação em students com dois $lookup: responsáveis que recebem aviso,
 *                        ativos e com e-mail VALID, e as 30 faltas mais recentes (pipeline idêntico ao de
 *                        FrequentQueriesTest::studentContextPipeline);
 *  - activeEnrollment(): leitura da CONSULTA 2 — a matrícula ativa traz turma e período; o nome da turma (snapshot)
 *                        e as datas do período vêm na MESMA agregação ($lookup em classes e academic_periods).
 */
final class StudentContextModel extends Model
{
    protected const COLLECTION = Collections::STUDENTS;

    /**
     * Aluno ATIVO com guardians_to_notify (_id, name, email) e absences (date, status, class_name, justification).
     *
     * @return array<string, mixed>|null null se o aluno não existe ou está arquivado
     */
    public function find(ObjectId $studentId): ?array
    {
        $rows = $this->collection()->aggregate(self::pipeline($studentId))->toArray();
        $row = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * Matrícula ativa do aluno com turma e período.
     *
     * @return array{enrollment_id: ObjectId, class_id: ObjectId, academic_period_id: ObjectId, start_date: mixed,
     *               class_name: string|null, period: array<string, mixed>|null}|null null sem matrícula ativa
     */
    public function activeEnrollment(ObjectId $studentId): ?array
    {
        $rows = $this->database->selectCollection(Collections::ENROLLMENTS)->aggregate([
            ['$match' => ['student_id' => $studentId, 'active' => true]],
            ['$limit' => 1],
            ['$lookup' => [
                'from' => Collections::CLASSES,
                'localField' => 'class_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['name' => 1]]],
                'as' => 'class',
            ]],
            ['$lookup' => [
                'from' => Collections::ACADEMIC_PERIODS,
                'localField' => 'academic_period_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['name' => 1, 'start_date' => 1, 'end_date' => 1, 'active' => 1]]],
                'as' => 'period',
            ]],
            ['$project' => ['class_id' => 1, 'academic_period_id' => 1, 'start_date' => 1, 'class' => 1, 'period' => 1]],
        ])->toArray();
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $class = $row['class'][0] ?? null;
        $period = $row['period'][0] ?? null;
        return [
            'enrollment_id' => $row['_id'],
            'class_id' => $row['class_id'],
            'academic_period_id' => $row['academic_period_id'],
            'start_date' => $row['start_date'] ?? null,
            'class_name' => is_array($class) && is_string($class['name'] ?? null) ? $class['name'] : null,
            'period' => is_array($period) ? $period : null,
        ];
    }

    /**
     * CONSULTA 1 (exata). Pública para os testes compararem com o pipeline documentado.
     *
     * @return list<array<string, mixed>>
     */
    public static function pipeline(ObjectId $studentId): array
    {
        return [
            ['$match' => ['_id' => $studentId, 'active' => true]],
            ['$addFields' => [
                'notify_ids' => [
                    '$map' => [
                        'input' => ['$filter' => [
                            'input' => '$guardians',
                            'as' => 'g',
                            'cond' => ['$eq' => ['$$g.receives_absence_notifications', true]],
                        ]],
                        'as' => 'g',
                        'in' => '$$g.guardian_id',
                    ],
                ],
            ]],
            ['$lookup' => [
                'from' => Collections::GUARDIANS,
                'localField' => 'notify_ids',
                'foreignField' => '_id',
                'pipeline' => [
                    ['$match' => ['active' => true, 'email_status' => EmailStatus::VALID->value]],
                    ['$project' => ['name' => 1, 'email' => 1]],
                ],
                'as' => 'guardians_to_notify',
            ]],
            ['$lookup' => [
                'from' => Collections::ATTENDANCE_RECORDS,
                'localField' => '_id',
                'foreignField' => 'student_id',
                'pipeline' => [
                    ['$match' => ['status' => ['$in' => AttendanceStatus::absenceValues()]]],
                    ['$sort' => ['date' => -1]],
                    ['$limit' => 30],
                    ['$project' => ['date' => 1, 'status' => 1, 'class_name' => 1, 'justification' => 1]],
                ],
                'as' => 'absences',
            ]],
            ['$project' => ['name' => 1, 'student_code' => 1, 'guardians_to_notify' => 1, 'absences' => 1]],
        ];
    }
}
