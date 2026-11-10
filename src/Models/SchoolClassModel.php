<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;

/**
 * Coleção classes (turmas de um período letivo). Código único dentro do período (uq_classes_period_code).
 * A listagem traz o nome do período e o total de matrículas ativas numa única agregação (sem N+1).
 */
final class SchoolClassModel extends Model
{
    protected const COLLECTION = Collections::CLASSES;

    /**
     * Turmas com o nome do período e o número de alunos com matrícula ativa.
     *
     * @return list<array<string, mixed>> cada item ganha period_name, period_active e student_count
     */
    public function listWithCounts(?ObjectId $periodId, ?string $shift, ?bool $active): array
    {
        $match = [];
        if ($periodId !== null) {
            $match['academic_period_id'] = $periodId;
        }
        if ($shift !== null) {
            $match['shift'] = $shift;
        }
        if ($active !== null) {
            $match['active'] = $active;
        }
        $pipeline = [
            ['$match' => $match === [] ? new \stdClass() : $match],
            ['$lookup' => [
                'from' => Collections::ACADEMIC_PERIODS,
                'localField' => 'academic_period_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['name' => 1, 'active' => 1, 'start_date' => 1]]],
                'as' => 'period',
            ]],
            ['$lookup' => [
                'from' => Collections::ENROLLMENTS,
                'localField' => '_id',
                'foreignField' => 'class_id',
                'pipeline' => [['$match' => ['active' => true]], ['$count' => 'n']],
                'as' => 'enrolled',
            ]],
            ['$addFields' => [
                'period_name' => ['$ifNull' => [['$first' => '$period.name'], null]],
                'period_active' => ['$ifNull' => [['$first' => '$period.active'], false]],
                'period_start' => ['$first' => '$period.start_date'],
                'student_count' => ['$ifNull' => [['$first' => '$enrolled.n'], 0]],
            ]],
            ['$project' => ['period' => 0, 'enrolled' => 0]],
            ['$sort' => ['period_start' => -1, 'code' => 1, '_id' => 1]],
        ];
        return array_values($this->collection()->aggregate($pipeline)->toArray());
    }

    /** @return array<string, mixed>|null */
    public function findById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $class = $this->collection()->findOne(['_id' => $oid]);
        return is_array($class) ? $class : null;
    }

    /** @return list<array<string, mixed>> turmas ativas do período, por código */
    public function activeOfPeriod(ObjectId $periodId): array
    {
        return array_values($this->collection()->find(
            ['academic_period_id' => $periodId, 'active' => true],
            ['sort' => ['code' => 1, '_id' => 1]]
        )->toArray());
    }

    /**
     * @param array<string, mixed> $document campos já validados (sem _id)
     * @return array<string, mixed>
     * @throws ConflictException código repetido no período
     */
    public function insert(array $document): array
    {
        $document = ['_id' => new ObjectId()] + $document;
        try {
            $this->collection()->insertOne($document);
        } catch (BulkWriteException $error) {
            throw self::conflict($error);
        }
        return $document;
    }

    /**
     * @param array<string, mixed> $set
     * @throws ConflictException código repetido no período
     */
    public function update(ObjectId $id, array $set): bool
    {
        try {
            $result = $this->collection()->updateOne(['_id' => $id], ['$set' => $set + ['updated_at' => Bson::now()]]);
        } catch (BulkWriteException $error) {
            throw self::conflict($error);
        }
        return $result->getMatchedCount() === 1;
    }

    public function setActive(ObjectId $id, bool $active): bool
    {
        $result = $this->collection()->updateOne(['_id' => $id], ['$set' => ['active' => $active, 'updated_at' => Bson::now()]]);
        return $result->getMatchedCount() === 1;
    }

    private static function conflict(BulkWriteException $error): \Throwable
    {
        return self::isDuplicateKey($error) ? new ConflictException('Já existe uma turma com este código no período.', $error) : $error;
    }
}
