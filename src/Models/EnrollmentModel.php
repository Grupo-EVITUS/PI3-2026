<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;

/**
 * Coleção enrollments (aluno ↔ turma ao longo do tempo). Uma matrícula ativa por aluno (uq_enrollments_student_active,
 * índice único parcial); matrículas encerradas guardam end_date e end_reason e nunca são apagadas.
 */
final class EnrollmentModel extends Model
{
    protected const COLLECTION = Collections::ENROLLMENTS;

    /** @return array<string, mixed>|null matrícula vigente do aluno */
    public function findActiveByStudent(ObjectId $studentId): ?array
    {
        $enrollment = $this->collection()->findOne(['student_id' => $studentId, 'active' => true]);
        return is_array($enrollment) ? $enrollment : null;
    }

    /**
     * Histórico do aluno, da matrícula mais recente para a mais antiga, com código/nome da turma e nome do período.
     *
     * @return list<array<string, mixed>>
     */
    public function historyOfStudent(ObjectId $studentId): array
    {
        $pipeline = [
            ['$match' => ['student_id' => $studentId]],
            ['$sort' => ['start_date' => -1, '_id' => -1]],
            ['$lookup' => [
                'from' => Collections::CLASSES,
                'localField' => 'class_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['code' => 1, 'name' => 1, 'shift' => 1]]],
                'as' => 'class',
            ]],
            ['$lookup' => [
                'from' => Collections::ACADEMIC_PERIODS,
                'localField' => 'academic_period_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['name' => 1]]],
                'as' => 'period',
            ]],
            ['$addFields' => ['class' => ['$first' => '$class'], 'period' => ['$first' => '$period']]],
        ];
        return array_values($this->collection()->aggregate($pipeline)->toArray());
    }

    /**
     * Alunos com matrícula ativa na turma (ordem alfabética), numa só agregação.
     *
     * @return list<array<string, mixed>> cada item: _id (matrícula), start_date e student (documento do aluno)
     */
    public function activeStudentsOfClass(ObjectId $classId): array
    {
        $pipeline = [
            ['$match' => ['class_id' => $classId, 'active' => true]],
            ['$lookup' => [
                'from' => Collections::STUDENTS,
                'localField' => 'student_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['name' => 1, 'student_code' => 1, 'birth_date' => 1, 'active' => 1, 'guardians' => 1]]],
                'as' => 'student',
            ]],
            ['$addFields' => ['student' => ['$first' => '$student']]],
            ['$match' => ['student' => ['$exists' => true]]],
            ['$sort' => ['student.name' => 1, '_id' => 1]],
        ];
        return array_values($this->collection()->aggregate($pipeline)->toArray());
    }

    public function countActiveOfClass(ObjectId $classId): int
    {
        return $this->collection()->countDocuments(['class_id' => $classId, 'active' => true]);
    }

    /**
     * Cria uma matrícula vigente.
     *
     * @return array<string, mixed>
     * @throws ConflictException o aluno já tem matrícula ativa (índice único parcial)
     */
    public function insertActive(ObjectId $studentId, ObjectId $classId, ObjectId $periodId, UTCDateTime $startDate): array
    {
        $now = Bson::now();
        $document = [
            '_id' => new ObjectId(),
            'student_id' => $studentId,
            'class_id' => $classId,
            'academic_period_id' => $periodId,
            'start_date' => $startDate,
            'end_date' => null,
            'end_reason' => null,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        try {
            $this->collection()->insertOne($document);
        } catch (BulkWriteException $error) {
            if (self::isDuplicateKey($error)) {
                throw new ConflictException('O aluno já tem uma matrícula ativa. Recarregue a página e tente novamente.', $error);
            }
            throw $error;
        }
        return $document;
    }

    /**
     * Encerra a matrícula (somente se ainda estiver ativa): o registro fica no histórico com data e motivo.
     *
     * @return bool false se ela já tinha sido encerrada por outra requisição
     */
    public function end(ObjectId $enrollmentId, UTCDateTime $endDate, string $reason): bool
    {
        $result = $this->collection()->updateOne(
            ['_id' => $enrollmentId, 'active' => true],
            ['$set' => ['active' => false, 'end_date' => $endDate, 'end_reason' => $reason, 'updated_at' => Bson::now()]]
        );
        return $result->getModifiedCount() === 1;
    }

    /** Desfaz um encerramento (usado só para reverter uma troca de turma que não pôde ser concluída). */
    public function reopen(ObjectId $enrollmentId): bool
    {
        try {
            $result = $this->collection()->updateOne(
                ['_id' => $enrollmentId, 'active' => false],
                ['$set' => ['active' => true, 'end_date' => null, 'end_reason' => null, 'updated_at' => Bson::now()]]
            );
        } catch (BulkWriteException) {
            return false;
        }
        return $result->getModifiedCount() === 1;
    }
}
