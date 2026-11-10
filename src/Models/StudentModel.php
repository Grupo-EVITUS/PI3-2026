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
 * Coleção students (aluno + vínculos com responsáveis embutidos em guardians[], no máximo 3).
 * Matrícula escolar (student_code) única. Listagens trazem a turma atual (matrícula ativa) por $lookup, sem N+1.
 */
final class StudentModel extends Model
{
    protected const COLLECTION = Collections::STUDENTS;

    /**
     * Busca paginada. Cada item ganha "class" (turma da matrícula ativa ou null) e "guardian_count".
     *
     * @param Regex|null $term filtro "contém" já escapado (Bson::contains) aplicado ao nome e à matrícula
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(?Regex $term, ?ObjectId $classId, bool $active, int $skip, int $limit): array
    {
        $match = ['active' => $active];
        if ($term !== null) {
            $match['$or'] = [['name' => $term], ['student_code' => $term]];
        }
        $pipeline = [['$match' => $match], ...self::currentEnrollmentStages()];
        if ($classId !== null) {
            $pipeline[] = ['$match' => ['enrollment.class_id' => $classId]];
        }
        $pipeline[] = ['$facet' => [
            'items' => [
                ['$sort' => ['name' => 1, '_id' => 1]],
                ['$skip' => max(0, $skip)],
                ['$limit' => max(1, $limit)],
                ...self::classStages(),
            ],
            'total' => [['$count' => 'n']],
        ]];
        $result = $this->collection()->aggregate($pipeline)->toArray();
        $facet = $result[0] ?? ['items' => [], 'total' => []];
        return [
            'items' => array_values($facet['items'] ?? []),
            'total' => (int)($facet['total'][0]['n'] ?? 0),
        ];
    }

    /**
     * Alunos ATIVOS que ainda não estão matriculados na turma informada (para o seletor "associar aluno").
     *
     * @return list<array<string, mixed>> com "class" (turma atual) para mostrar de onde o aluno sairá
     */
    public function pickable(?Regex $term, ?ObjectId $excludeClassId, int $limit): array
    {
        $match = ['active' => true];
        if ($term !== null) {
            $match['$or'] = [['name' => $term], ['student_code' => $term]];
        }
        $pipeline = [['$match' => $match], ...self::currentEnrollmentStages()];
        if ($excludeClassId !== null) {
            $pipeline[] = ['$match' => ['enrollment.class_id' => ['$ne' => $excludeClassId]]];
        }
        $pipeline[] = ['$sort' => ['name' => 1, '_id' => 1]];
        $pipeline[] = ['$limit' => max(1, $limit)];
        array_push($pipeline, ...self::classStages());
        return array_values($this->collection()->aggregate($pipeline)->toArray());
    }

    /** @return list<array<string, mixed>> alunos (ativos ou não) vinculados ao responsável, com a turma atual */
    public function findByGuardian(ObjectId $guardianId): array
    {
        $pipeline = [
            ['$match' => ['guardians.guardian_id' => $guardianId]],
            ['$sort' => ['active' => -1, 'name' => 1, '_id' => 1]],
            ...self::currentEnrollmentStages(),
            ...self::classStages(),
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
        $student = $this->collection()->findOne(['_id' => $oid]);
        return is_array($student) ? $student : null;
    }

    /** @return array<string, mixed>|null aluno pela matrícula escolar exata */
    public function findByCode(string $studentCode): ?array
    {
        $student = $this->collection()->findOne(['student_code' => $studentCode]);
        return is_array($student) ? $student : null;
    }

    /**
     * @param array<string, mixed> $document campos já validados (sem _id)
     * @return array<string, mixed>
     * @throws ConflictException matrícula escolar repetida
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
     * @throws ConflictException matrícula escolar repetida
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

    /**
     * Substitui os vínculos com responsáveis com controle otimista: só grava se o array ainda for exatamente o lido
     * ($expected). Assim duas pessoas editando ao mesmo tempo não perdem alterações uma da outra.
     *
     * @param list<array<string, mixed>> $expected vínculos lidos do banco
     * @param list<array<string, mixed>> $guardians novos vínculos (o validator garante ≤ 3, um principal, sem repetição)
     * @return bool false se o cadastro mudou desde a leitura
     */
    public function replaceGuardians(ObjectId $id, array $expected, array $guardians): bool
    {
        $result = $this->collection()->updateOne(
            ['_id' => $id, 'guardians' => array_values($expected)],
            ['$set' => ['guardians' => array_values($guardians), 'updated_at' => Bson::now()]]
        );
        return $result->getMatchedCount() === 1;
    }

    /** @return list<array<string, mixed>> etapas: matrícula ativa em "enrollment" (ou ausente) */
    private static function currentEnrollmentStages(): array
    {
        return [
            ['$lookup' => [
                'from' => Collections::ENROLLMENTS,
                'localField' => '_id',
                'foreignField' => 'student_id',
                'pipeline' => [
                    ['$match' => ['active' => true]],
                    ['$limit' => 1],
                    ['$project' => ['class_id' => 1, 'academic_period_id' => 1, 'start_date' => 1]],
                ],
                'as' => 'enrollment',
            ]],
            ['$addFields' => ['enrollment' => ['$first' => '$enrollment']]],
        ];
    }

    /** @return list<array<string, mixed>> etapas: turma da matrícula ativa em "class" e total de responsáveis */
    private static function classStages(): array
    {
        return [
            ['$lookup' => [
                'from' => Collections::CLASSES,
                'localField' => 'enrollment.class_id',
                'foreignField' => '_id',
                'pipeline' => [['$project' => ['code' => 1, 'name' => 1, 'shift' => 1, 'academic_period_id' => 1]]],
                'as' => 'class',
            ]],
            ['$addFields' => [
                'class' => ['$first' => '$class'],
                'guardian_count' => ['$size' => ['$ifNull' => ['$guardians', []]]],
            ]],
        ];
    }

    private static function conflict(BulkWriteException $error): \Throwable
    {
        return self::isDuplicateKey($error) ? new ConflictException('Já existe um aluno com esta matrícula escolar.', $error) : $error;
    }
}
