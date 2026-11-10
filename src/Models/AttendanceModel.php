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
}
