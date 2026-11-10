<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;

/**
 * Coleção guardians (contato dos responsáveis, referenciado por students.guardians[].guardian_id).
 * E-mail único pelo valor normalizado (uq_guardians_email_normalized).
 */
final class GuardianModel extends Model
{
    protected const COLLECTION = Collections::GUARDIANS;

    /**
     * Busca paginada por nome ou e-mail. Cada item ganha "student_count" (alunos ativos vinculados), numa só agregação.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(?Regex $term, bool $active, int $skip, int $limit): array
    {
        $match = ['active' => $active];
        if ($term !== null) {
            $match['$or'] = [['name' => $term], ['email_normalized' => $term]];
        }
        $pipeline = [
            ['$match' => $match],
            ['$facet' => [
                'items' => [
                    ['$sort' => ['name' => 1, '_id' => 1]],
                    ['$skip' => max(0, $skip)],
                    ['$limit' => max(1, $limit)],
                    ['$lookup' => [
                        'from' => Collections::STUDENTS,
                        'localField' => '_id',
                        'foreignField' => 'guardians.guardian_id',
                        'pipeline' => [['$match' => ['active' => true]], ['$count' => 'n']],
                        'as' => 'children',
                    ]],
                    ['$addFields' => ['student_count' => ['$ifNull' => [['$first' => '$children.n'], 0]]]],
                    ['$project' => ['children' => 0]],
                ],
                'total' => [['$count' => 'n']],
            ]],
        ];
        $result = $this->collection()->aggregate($pipeline)->toArray();
        $facet = $result[0] ?? ['items' => [], 'total' => []];
        return [
            'items' => array_values($facet['items'] ?? []),
            'total' => (int)($facet['total'][0]['n'] ?? 0),
        ];
    }

    /**
     * Responsáveis ativos para o seletor "vincular responsável existente".
     *
     * @param list<ObjectId> $exclude responsáveis já vinculados ao aluno
     * @return list<array<string, mixed>>
     */
    public function pickable(?Regex $term, array $exclude, int $limit): array
    {
        $filter = ['active' => true];
        if ($term !== null) {
            $filter['$or'] = [['name' => $term], ['email_normalized' => $term]];
        }
        if ($exclude !== []) {
            $filter['_id'] = ['$nin' => array_values($exclude)];
        }
        return array_values($this->collection()->find($filter, ['sort' => ['name' => 1, '_id' => 1], 'limit' => max(1, $limit)])->toArray());
    }

    /** @return array<string, mixed>|null */
    public function findById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $guardian = $this->collection()->findOne(['_id' => $oid]);
        return is_array($guardian) ? $guardian : null;
    }

    /**
     * @param list<ObjectId> $ids
     * @return array<string, array<string, mixed>> id (hex) => documento
     */
    public function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $found = [];
        foreach ($this->collection()->find(['_id' => ['$in' => array_values($ids)]]) as $guardian) {
            $found[(string)$guardian['_id']] = $guardian;
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $document campos já validados (sem _id)
     * @return array<string, mixed>
     * @throws ConflictException e-mail já cadastrado
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
     * @throws ConflictException e-mail já cadastrado
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
        return self::isDuplicateKey($error) ? new ConflictException('Já existe um responsável com este e-mail.', $error) : $error;
    }
}
