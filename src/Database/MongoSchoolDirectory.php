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

    private function write(ObjectId $actor, callable $operation, string $action, string $entity): ObjectId
    {
        $id = new ObjectId();
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
