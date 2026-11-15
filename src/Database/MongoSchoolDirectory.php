<?php
declare(strict_types=1);

namespace Saa\Database;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use RuntimeException;
use function MongoDB\with_transaction;

/**
 * Cadastros escolares do runtime Mongo. Cada mudança e sua auditoria são atômicas.
 */
final class MongoSchoolDirectory
{
    public function __construct(private Database $database)
    {
    }

    public function createClass(ObjectId $actor, string $name, string $grade, int $year, string $shift): ObjectId
    {
        $name = self::requiredText($name, 120);
        $grade = self::requiredText($grade, 80);
        if ($year < 2000 || $year > 2100 || !in_array($shift, ['Manhã', 'Tarde', 'Noite', 'Integral'], true)) {
            throw new InvalidArgumentException('Ano ou turno inválido.');
        }
        return $this->write($actor, function (Session $session, ObjectId $id) use ($name, $grade, $year, $shift): void {
            $this->database->classes->insertOne([
                '_id' => $id, 'name' => $name, 'grade' => $grade, 'academic_year' => $year,
                'shift' => $shift, 'active' => true, 'created_at' => new UTCDateTime(),
            ], ['session' => $session]);
        }, 'TURMA_CRIADA', 'turma');
    }

    public function createStudent(ObjectId $actor, string $name, ?string $registration, ObjectId $classId): ObjectId
    {
        $name = self::requiredText($name, 120);
        $registration = $registration === null || trim($registration) === ''
            ? null : self::requiredText($registration, 80);
        return $this->write($actor, function (Session $session, ObjectId $id) use ($name, $registration, $classId): void {
            if ($this->database->classes->countDocuments(['_id' => $classId, 'active' => true], ['session' => $session]) !== 1) {
                throw new InvalidArgumentException('Turma não encontrada ou inativa.');
            }
            $this->database->students->insertOne([
                '_id' => $id, 'name' => $name, 'registration_no' => $registration,
                'class_id' => $classId, 'active' => true, 'created_at' => new UTCDateTime(),
            ], ['session' => $session]);
        }, 'ALUNO_CRIADO', 'aluno');
    }

    /** @param list<ObjectId> $studentIds */
    public function createGuardian(
        ObjectId $actor, string $name, string $email, string $relationship, array $studentIds
    ): ObjectId {
        $name = self::requiredText($name, 120);
        $email = strtolower(trim($email));
        $relationship = self::requiredText($relationship, 80);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail do responsável inválido.');
        }
        $unique = [];
        foreach ($studentIds as $studentId) {
            if (!$studentId instanceof ObjectId) {
                throw new InvalidArgumentException('Identificador de aluno inválido.');
            }
            $unique[(string)$studentId] = $studentId;
        }
        return $this->write($actor, function (Session $session, ObjectId $id) use ($name, $email, $relationship, $unique): void {
            $options = ['session' => $session];
            if ($unique !== [] && $this->database->students->countDocuments(
                ['_id' => ['$in' => array_values($unique)], 'active' => true], $options
            ) !== count($unique)) {
                throw new InvalidArgumentException('Um ou mais alunos não estão disponíveis.');
            }
            $this->database->guardians->insertOne([
                '_id' => $id, 'name' => $name, 'email' => $email, 'relationship' => $relationship,
                'active' => true, 'created_at' => new UTCDateTime(),
            ], $options);
            foreach ($unique as $studentId) {
                $this->database->student_guardians->insertOne([
                    '_id' => new ObjectId(), 'student_id' => $studentId, 'guardian_id' => $id,
                ], $options);
            }
        }, 'RESPONSAVEL_CRIADO', 'responsavel');
    }

    public function updateClass(
        ObjectId $actor, ObjectId $classId, string $name, string $grade, int $year, string $shift
    ): ObjectId {
        $name = self::requiredText($name, 120);
        $grade = self::requiredText($grade, 80);
        if ($year < 2000 || $year > 2100 || !in_array($shift, ['Manhã', 'Tarde', 'Noite', 'Integral'], true)) {
            throw new InvalidArgumentException('Ano ou turno inválido.');
        }
        return $this->write($actor, function (Session $session) use ($classId, $name, $grade, $year, $shift): void {
            $result = $this->database->classes->updateOne(
                ['_id' => $classId, 'active' => true],
                ['$set' => ['name' => $name, 'grade' => $grade, 'academic_year' => $year, 'shift' => $shift]],
                ['session' => $session]
            );
            if ($result->getMatchedCount() !== 1) {
                throw new InvalidArgumentException('Turma não encontrada ou inativa.');
            }
        }, 'TURMA_ATUALIZADA', 'turma', $classId);
    }

    public function updateStudent(
        ObjectId $actor, ObjectId $studentId, string $name, ?string $registration, ObjectId $classId
    ): ObjectId {
        $name = self::requiredText($name, 120);
        $registration = $registration === null || trim($registration) === ''
            ? null : self::requiredText($registration, 80);
        return $this->write($actor, function (Session $session) use ($studentId, $name, $registration, $classId): void {
            $options = ['session' => $session];
            if ($this->database->classes->countDocuments(['_id' => $classId, 'active' => true], $options) !== 1) {
                throw new InvalidArgumentException('Turma não encontrada ou inativa.');
            }
            $result = $this->database->students->updateOne(
                ['_id' => $studentId, 'active' => true],
                ['$set' => ['name' => $name, 'registration_no' => $registration, 'class_id' => $classId]],
                $options
            );
            if ($result->getMatchedCount() !== 1) {
                throw new InvalidArgumentException('Aluno não encontrado ou inativo.');
            }
        }, 'ALUNO_ATUALIZADO', 'aluno', $studentId);
    }

    /** @param list<ObjectId> $studentIds */
    public function updateGuardian(
        ObjectId $actor, ObjectId $guardianId, string $name, string $email, string $relationship, array $studentIds
    ): ObjectId {
        $name = self::requiredText($name, 120);
        $email = strtolower(trim($email));
        $relationship = self::requiredText($relationship, 80);
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail do responsável inválido.');
        }
        $unique = [];
        foreach ($studentIds as $studentId) {
            if (!$studentId instanceof ObjectId) {
                throw new InvalidArgumentException('Identificador de aluno inválido.');
            }
            $unique[(string)$studentId] = $studentId;
        }
        return $this->write($actor, function (Session $session) use ($guardianId, $name, $email, $relationship, $unique): void {
            $options = ['session' => $session];
            if ($unique !== [] && $this->database->students->countDocuments(
                ['_id' => ['$in' => array_values($unique)], 'active' => true], $options
            ) !== count($unique)) {
                throw new InvalidArgumentException('Um ou mais alunos não estão disponíveis.');
            }
            $result = $this->database->guardians->updateOne(
                ['_id' => $guardianId, 'active' => true],
                ['$set' => ['name' => $name, 'email' => $email, 'relationship' => $relationship]], $options
            );
            if ($result->getMatchedCount() !== 1) {
                throw new InvalidArgumentException('Responsável não encontrado ou inativo.');
            }
            $linkedStudentIds = [];
            foreach ($this->database->student_guardians->find(
                ['guardian_id' => $guardianId], ['projection' => ['student_id' => 1], 'session' => $session]
            ) as $link) {
                $linkedStudentIds[] = $link['student_id'];
            }
            $activeStudentIds = [];
            if ($linkedStudentIds !== []) {
                foreach ($this->database->students->find(
                    ['_id' => ['$in' => $linkedStudentIds], 'active' => true],
                    ['projection' => ['_id' => 1], 'session' => $session]
                ) as $student) {
                    $activeStudentIds[] = $student['_id'];
                }
            }
            if ($activeStudentIds !== []) {
                $this->database->student_guardians->deleteMany(
                    ['guardian_id' => $guardianId, 'student_id' => ['$in' => $activeStudentIds]], $options
                );
            }
            foreach ($unique as $studentId) {
                $this->database->student_guardians->insertOne(
                    ['_id' => new ObjectId(), 'student_id' => $studentId, 'guardian_id' => $guardianId], $options
                );
            }
        }, 'RESPONSAVEL_ATUALIZADO', 'responsavel', $guardianId);
    }

    public function archive(ObjectId $actor, string $entity, ObjectId $id): ObjectId
    {
        $targets = [
            'turma' => ['classes', 'TURMA_ARQUIVADA'],
            'aluno' => ['students', 'ALUNO_ARQUIVADO'],
            'responsavel' => ['guardians', 'RESPONSAVEL_ARQUIVADO'],
        ];
        if (!isset($targets[$entity])) {
            throw new InvalidArgumentException('Tipo de cadastro inválido.');
        }
        [$collection, $action] = $targets[$entity];
        return $this->write($actor, function (Session $session) use ($entity, $collection, $id): void {
            $options = ['session' => $session];
            if ($entity === 'turma' && $this->database->students->countDocuments(
                ['class_id' => $id, 'active' => true], $options
            ) > 0) {
                throw new InvalidArgumentException('Arquive ou transfira os alunos ativos antes de arquivar a turma.');
            }
            $result = $this->database->selectCollection($collection)->updateOne(
                ['_id' => $id, 'active' => true], ['$set' => ['active' => false]], $options
            );
            if ($result->getMatchedCount() !== 1) {
                throw new InvalidArgumentException('Cadastro não encontrado ou já arquivado.');
            }
        }, $action, $entity, $id);
    }

    private function write(ObjectId $actor, callable $operation, string $action, string $entity, ?ObjectId $targetId = null): ObjectId
    {
        $id = $targetId ?? new ObjectId();
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($actor, $operation, $action, $entity, $id): void {
                if ($this->database->users->countDocuments(
                    ['_id' => $actor, 'profile' => 'COORDENACAO', 'active' => true], ['session' => $session]
                ) !== 1) {
                    throw new RuntimeException('Cadastro permitido somente à coordenação ativa.');
                }
                $operation($session, $id);
                (new MongoOperationLogWriter($this->database->operation_logs))
                    ->append($actor, $action, $entity, $id, '', $session);
            });
            return $id;
        } finally {
            $session->endSession();
        }
    }

    private static function requiredText(string $value, int $maxBytes): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxBytes || !mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('Campo de cadastro inválido.');
        }
        return $value;
    }
}
