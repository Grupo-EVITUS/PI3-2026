<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\Mongo;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function rejected(callable $operation, int $code): void {
    try { $operation(); } catch (BulkWriteException $error) {
        $codes = array_map(static fn($e) => $e->getCode(), $error->getWriteResult()->getWriteErrors());
        check(in_array($code, $codes, true), 'Código de rejeição inesperado.');
        return;
    }
    throw new RuntimeException('MongoDB aceitou operação que deveria rejeitar.');
}

// Banco efêmero independente da base configurada; nunca limpar banco da aplicação.
$db = Mongo::database('saa_schema_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $now = new UTCDateTime();
    $user = ['name' => 'Teste', 'email' => 'Teste@Example.org', 'email_key' => 'teste@example.org',
        'password_hash' => 'test-only', 'profile' => 'AOE', 'active' => true, 'created_at' => $now];
    $classId = new ObjectId();
    $studentId = new ObjectId();
    $guardianId = new ObjectId();
    $userId = new ObjectId();
    $absenceId = new ObjectId();
    $communicationId = new ObjectId();
    $fixtures = [
        'users' => ['_id' => $userId] + $user,
        'classes' => ['_id' => $classId, 'name' => 'A', 'grade' => '1', 'academic_year' => 2026,
            'shift' => 'Manhã', 'active' => true, 'created_at' => $now],
        'students' => ['_id' => $studentId, 'name' => 'Aluno', 'class_id' => $classId, 'active' => true, 'created_at' => $now],
        'guardians' => ['_id' => $guardianId, 'name' => 'Responsável', 'email' => 'shared@example.org',
            'relationship' => 'Mãe', 'active' => true, 'created_at' => $now],
        'student_guardians' => ['student_id' => $studentId, 'guardian_id' => $guardianId],
        'absences' => ['_id' => $absenceId, 'student_id' => $studentId, 'absence_date' => '2026-11-13',
            'status' => 'PENDENTE', 'registered_by' => $userId, 'created_at' => $now,
            'class_snapshot_source' => 'unknown'],
        'communications' => ['_id' => $communicationId, 'absence_id' => $absenceId, 'guardian_id' => $guardianId,
            'email_to' => 'shared@example.org', 'token_hash' => 'hash-test', 'status' => 'PENDENTE', 'created_at' => $now],
        'mail_queue' => ['communication_id' => $communicationId, 'recipient' => 'shared@example.org',
            'subject' => 'Teste', 'body' => 'Não enviar', 'status' => 'QUARENTENA', 'created_at' => $now],
        'justifications' => ['absence_id' => $absenceId, 'communication_id' => $communicationId,
            'guardian_id' => $guardianId, 'reason' => 'OUTRO', 'explanation' => '', 'submitted_at' => $now],
        'operation_logs' => ['user_id' => null, 'action' => 'test', 'entity' => 'test', 'details' => '', 'happened_at' => $now],
    ];
    foreach ($fixtures as $name => $document) {
        $db->selectCollection($name)->insertOne($document);
        rejected(fn() => $db->selectCollection($name)->insertOne(['invalid' => true]), 121);
    }
    foreach (Schema::indexes() as $name => $expectedIndexes) {
        $actual = [];
        foreach ($db->selectCollection($name)->listIndexes() as $index) {
            $actual[$index->getName()] = $index;
        }
        foreach ($expectedIndexes as $expected) {
            check(isset($actual[$expected['name']]), 'Índice ausente.');
            check($actual[$expected['name']]->getKey() === $expected['key'], 'Chaves de índice divergentes.');
            check($actual[$expected['name']]->isUnique() === ($expected['unique'] ?? false), 'Unicidade divergente.');
        }
    }
    $before = [];
    foreach ($fixtures as $name => $_) {
        $before[$name] = $db->selectCollection($name)->countDocuments();
    }
    Schema::apply($db);
    Schema::apply($db);
    foreach ($before as $name => $count) {
        check($db->selectCollection($name)->countDocuments() === $count, 'Bootstrap alterou dados.');
    }
    check(!in_array('attendance_sessions', iterator_to_array($db->listCollectionNames(), false), true),
        'Chamada futura criada prematuramente.');

    $duplicate = $user;
    $duplicate['email'] = 'TESTE@example.org';
    rejected(fn() => $db->users->insertOne($duplicate), 11000);
    $duplicate['email_key'] = 'forged';
    rejected(fn() => $db->users->insertOne($duplicate), 121);
    $badProfile = $user;
    $badProfile['profile'] = 'ADMIN';
    rejected(fn() => $db->users->insertOne($badProfile), 121);
    $initialAdmin = $user;
    $initialAdmin['email'] = 'first-admin@example.org';
    $initialAdmin['email_key'] = 'first-admin@example.org';
    $initialAdmin['is_initial_admin'] = true;
    $db->users->insertOne($initialAdmin);
    $secondInitialAdmin = $user;
    $secondInitialAdmin['email'] = 'second@example.org';
    $secondInitialAdmin['email_key'] = 'second@example.org';
    $secondInitialAdmin['is_initial_admin'] = true;
    rejected(fn() => $db->users->insertOne($secondInitialAdmin), 11000);
    $badInitialAdminMarker = $user;
    $badInitialAdminMarker['is_initial_admin'] = 'true';
    rejected(fn() => $db->users->insertOne($badInitialAdminMarker), 121);
    rejected(fn() => $db->users->updateOne(['_id' => $userId], ['$set' => ['active' => 1]]), 121);
    check(Schema::emailKey('ÄBC@Example.org') === 'Äbc@example.org', 'Normalização incompatível com NOCASE.');

    $student = $fixtures['students'];
    unset($student['_id']);
    $db->students->insertOne($student);
    $student['registration_no'] = null;
    $db->students->insertOne($student);
    $db->students->insertOne($student);
    $student['registration_no'] = 'R-1';
    $db->students->insertOne($student);
    rejected(fn() => $db->students->insertOne($student), 11000);
    $student['class_id'] = 'invalid-id';
    rejected(fn() => $db->students->insertOne($student), 121);

    $guardian = $fixtures['guardians'];
    unset($guardian['_id']);
    $db->guardians->insertOne($guardian); // E-mail não é uma identidade única.
    foreach (['student_guardians', 'absences', 'communications', 'mail_queue', 'justifications'] as $name) {
        $duplicate = $fixtures[$name];
        unset($duplicate['_id']);
        rejected(fn() => $db->selectCollection($name)->insertOne($duplicate), 11000);
    }
    rejected(fn() => $db->absences->updateOne(['_id' => $absenceId], ['$set' => ['status' => 'PRESENTE']]), 121);
    check($db->mail_queue->findOne(['communication_id' => $communicationId])['status'] === 'QUARENTENA',
        'Fila deixou de estar em quarentena.');

    // Bootstrap deve recusar legado incompatível, sem apagá-lo.
    $db->command(['collMod' => 'operation_logs', 'validationLevel' => 'off'])->toArray();
    $db->operation_logs->insertOne(['legacy_invalid' => true]);
    try {
        Schema::apply($db);
        throw new LogicException('Bootstrap aceitou dado incompatível.');
    } catch (RuntimeException $error) {
        check(str_contains($error->getMessage(), 'operation_logs'), 'Falha inesperada no bootstrap.');
    }
    check($db->operation_logs->countDocuments(['legacy_invalid' => true]) === 1, 'Dado legado foi apagado.');
    fwrite(STDOUT, "MongoSchemaTest: validators, unicidade, compatibilidade e bootstrap idempotente passaram.\n");
} finally {
    $db->drop();
}
