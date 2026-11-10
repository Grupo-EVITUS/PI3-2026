<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;

/**
 * Coleção academic_periods (períodos letivos com a regra de frequência embutida em attendance_policy).
 * Índices: uq_academic_periods_name (nome único) e uq_academic_periods_single_active (no máximo um ativo).
 */
final class AcademicPeriodModel extends Model
{
    protected const COLLECTION = Collections::ACADEMIC_PERIODS;

    /** @return list<array<string, mixed>> todos os períodos, do mais recente para o mais antigo */
    public function all(): array
    {
        return array_values($this->collection()->find([], ['sort' => ['start_date' => -1, '_id' => -1]])->toArray());
    }

    /** @return array<string, mixed>|null */
    public function findById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $period = $this->collection()->findOne(['_id' => $oid]);
        return is_array($period) ? $period : null;
    }

    /** @return array<string, mixed>|null o período vigente (active=true), se houver */
    public function findActive(): ?array
    {
        $period = $this->collection()->findOne(['active' => true]);
        return is_array($period) ? $period : null;
    }

    /**
     * @param array<string, mixed> $document campos já validados (sem _id)
     * @return array<string, mixed> documento gravado
     * @throws ConflictException nome repetido ou segundo período ativo
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
     * @param array<string, mixed> $set campos já validados
     * @return bool true se o período existe
     * @throws ConflictException nome repetido
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

    /**
     * Torna o período o único vigente: desativa os demais e ativa este. O índice único parcial garante que,
     * mesmo com duas ativações simultâneas, nunca haverá dois períodos ativos.
     *
     * @throws ConflictException outra ativação aconteceu ao mesmo tempo
     */
    public function activate(ObjectId $id): bool
    {
        $now = Bson::now();
        $this->collection()->updateMany(
            ['active' => true, '_id' => ['$ne' => $id]],
            ['$set' => ['active' => false, 'updated_at' => $now]]
        );
        try {
            $result = $this->collection()->updateOne(['_id' => $id], ['$set' => ['active' => true, 'updated_at' => $now]]);
        } catch (BulkWriteException $error) {
            throw self::conflict($error, 'Outro período foi ativado ao mesmo tempo. Recarregue a página e tente novamente.');
        }
        return $result->getMatchedCount() === 1;
    }

    private static function conflict(BulkWriteException $error, string $message = 'Já existe um período letivo com este nome.'): \Throwable
    {
        return self::isDuplicateKey($error) ? new ConflictException($message, $error) : $error;
    }
}
