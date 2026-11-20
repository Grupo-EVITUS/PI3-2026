<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use InvalidArgumentException;
use function MongoDB\with_transaction;

/** Carga fictícia explícita para um banco MongoDB descartável de homologação. */
final class DemoSchoolSeed
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array<string, int> */
    public function apply(): array
    {
        $name = $this->database->getDatabaseName();
        if (preg_match('/^saa_demo_[A-Za-z0-9_]{1,40}$/D', $name) !== 1
            || $name === trim((string)getenv('MONGODB_DB'))) {
            throw new InvalidArgumentException('Use um banco novo saa_demo_* diferente do banco operacional.');
        }
        if (iterator_to_array($this->database->listCollectionNames(), false) !== []) {
            throw new InvalidArgumentException('Banco de demonstração já inicializado; use outro banco novo.');
        }

        // Reserva o banco antes do DDL; se falhar, a carga parcial nunca é retomada implicitamente.
        $this->database->demo_seed_runs->insertOne([
            '_id' => 'v1', 'status' => 'INICIADO', 'created_at' => new UTCDateTime(),
        ]);
        Schema::apply($this->database);
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session): void {
                $options = ['session' => $session];
                $now = new UTCDateTime();
                $classes = [new ObjectId(), new ObjectId()];
                $this->database->classes->insertMany([
                    ['_id' => $classes[0], 'name' => '5º ano A', 'grade' => '5º ano',
                        'academic_year' => 2026, 'shift' => 'Manhã', 'active' => true, 'created_at' => $now],
                    ['_id' => $classes[1], 'name' => '7º ano B', 'grade' => '7º ano',
                        'academic_year' => 2026, 'shift' => 'Tarde', 'active' => true, 'created_at' => $now],
                ], $options);
                $names = ['Lucas Almeida', 'Marina Souza', 'Pedro Santos', 'Julia Costa', 'Rafael Lima'];
                $students = [];
                $studentIds = [];
                foreach ($names as $index => $studentName) {
                    $studentIds[] = $id = new ObjectId();
                    $students[] = ['_id' => $id, 'name' => $studentName,
                        'registration_no' => sprintf('DEMO-2026-%03d', $index + 1),
                        'class_id' => $classes[$index < 3 ? 0 : 1],
                        'active' => true, 'created_at' => $now];
                }
                $this->database->students->insertMany($students, $options);
                $guardians = [new ObjectId(), new ObjectId(), new ObjectId()];
                $this->database->guardians->insertMany([
                    ['_id' => $guardians[0], 'name' => 'Maria Almeida',
                        'email' => 'maria.almeida@example.test', 'relationship' => 'Mãe',
                        'active' => true, 'created_at' => $now],
                    ['_id' => $guardians[1], 'name' => 'João Souza',
                        'email' => 'joao.souza@example.test', 'relationship' => 'Pai',
                        'active' => true, 'created_at' => $now],
                    ['_id' => $guardians[2], 'name' => 'Ana Costa',
                        'email' => 'ana.costa@example.test', 'relationship' => 'Mãe',
                        'active' => true, 'created_at' => $now],
                ], $options);
                $links = [];
                foreach ([[0, 0], [1, 1], [3, 2]] as [$student, $guardian]) {
                    $links[] = ['_id' => new ObjectId(), 'student_id' => $studentIds[$student],
                        'guardian_id' => $guardians[$guardian]];
                }
                $this->database->student_guardians->insertMany($links, $options);
                $this->database->demo_seed_runs->updateOne(['_id' => 'v1'],
                    ['$set' => ['status' => 'CONCLUIDO', 'completed_at' => $now]], $options);
            });
        } finally {
            $session->endSession();
        }

        return ['classes' => 2, 'students' => 5, 'guardians' => 3, 'student_guardians' => 3];
    }
}
