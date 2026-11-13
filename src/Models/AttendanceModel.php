<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Operation\FindOneAndUpdate;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Domain\AttendanceStatus;

/**
 * Coleção attendance_records: um registro por aluno por dia (índice único uq_attendance_student_date), com snapshot
 * da turma, details e justification embutidos. Registros nunca são apagados.
 */
final class AttendanceModel extends Model
{
    protected const COLLECTION = Collections::ATTENDANCE_RECORDS;
    public const DUPLICATE_MESSAGE = 'Já existe ausência registrada para este aluno nesta data.';

    /** @return array<string, mixed>|null */
    public function findById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $record = $this->collection()->findOne(['_id' => $oid]);
        return is_array($record) ? $record : null;
    }

    /** @return array<string, mixed>|null registro do aluno no dia (meia-noite UTC) */
    public function findByStudentAndDate(ObjectId $studentId, UTCDateTime $day): ?array
    {
        $record = $this->collection()->findOne(['student_id' => $studentId, 'date' => $day]);
        return is_array($record) ? $record : null;
    }

    /**
     * Grava um novo registro (documento já montado pelo serviço) e o devolve com _id.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     * @throws ConflictException já existe registro do aluno no dia (índice único)
     */
    public function insert(array $document): array
    {
        $document['_id'] ??= new ObjectId();
        try {
            $this->collection()->insertOne($document);
        } catch (BulkWriteException $error) {
            if (self::isDuplicateKey($error)) {
                throw new ConflictException(self::DUPLICATE_MESSAGE, $error);
            }
            throw $error;
        }
        return $document;
    }

    /**
     * Converte uma PRESENÇA em FALTA de forma atômica (o filtro exige status PRESENT: se outra pessoa já lançou a
     * falta, nada é alterado e o retorno é null).
     *
     * @param array<string, mixed> $set campos a gravar (status, details, justification, snapshot da turma...)
     * @return array<string, mixed>|null documento atualizado
     */
    public function convertPresentToAbsent(ObjectId $id, array $set): ?array
    {
        $set['status'] = AttendanceStatus::ABSENT->value;
        $record = $this->collection()->findOneAndUpdate(
            ['_id' => $id, 'status' => AttendanceStatus::PRESENT->value],
            ['$set' => $set],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
        );
        return is_array($record) ? $record : null;
    }

    /**
     * Consulta de frequência com filtros (API JSON), mais recentes primeiro. Cada item ganha "student" (_id, name,
     * student_code) por $lookup, sem N+1. Todos os filtros chegam já tipados pelo serviço.
     *
     * @param array{student_id?: ObjectId, class_id?: ObjectId, date_from?: UTCDateTime, date_to?: UTCDateTime,
     *              status?: string, justification_status?: string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $skip, int $limit): array
    {
        $match = [];
        if (isset($filters['student_id'])) {
            $match['student_id'] = $filters['student_id'];
        }
        if (isset($filters['class_id'])) {
            $match['class_id'] = $filters['class_id'];
        }
        $range = [];
        if (isset($filters['date_from'])) {
            $range['$gte'] = $filters['date_from'];
        }
        if (isset($filters['date_to'])) {
            $range['$lte'] = $filters['date_to'];
        }
        if ($range !== []) {
            $match['date'] = $range;
        }
        if (isset($filters['status'])) {
            $match['status'] = $filters['status'];
        }
        if (isset($filters['justification_status'])) {
            $match['justification.status'] = $filters['justification_status'];
        }
        $result = $this->collection()->aggregate([
            ['$match' => $match === [] ? new \stdClass() : $match],
            ['$facet' => [
                'items' => [
                    ['$sort' => ['date' => -1, '_id' => -1]],
                    ['$skip' => max(0, $skip)],
                    ['$limit' => max(1, $limit)],
                    ...self::studentStages(),
                ],
                'total' => [['$count' => 'n']],
            ]],
        ])->toArray();
        $facet = $result[0] ?? ['items' => [], 'total' => []];
        return [
            'items' => array_values($facet['items'] ?? []),
            'total' => (int)($facet['total'][0]['n'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null registro com "student" (_id, name, student_code) */
    public function findWithStudent(ObjectId $id): ?array
    {
        $rows = $this->collection()->aggregate([
            ['$match' => ['_id' => $id]],
            ['$limit' => 1],
            ...self::studentStages(),
        ])->toArray();
        $row = $rows[0] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * Ids dos registros de frequência do aluno (usa o índice único aluno + data).
     *
     * @return list<ObjectId>
     */
    public function idsOfStudent(ObjectId $studentId): array
    {
        $ids = [];
        foreach ($this->collection()->find(['student_id' => $studentId], ['projection' => ['_id' => 1], 'sort' => ['date' => -1]]) as $row) {
            $ids[] = $row['_id'];
        }
        return $ids;
    }

    /** @return list<array<string, mixed>> estágios que acrescentam "student" (ou null se o aluno não existir) */
    private static function studentStages(): array
    {
        return [
            ['$lookup' => [
                'from' => Collections::STUDENTS,
                'localField' => 'student_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['name' => 1, 'student_code' => 1]]],
                'as' => 'student',
            ]],
            ['$set' => ['student' => ['$ifNull' => [['$first' => '$student'], null]]]],
        ];
    }
}
