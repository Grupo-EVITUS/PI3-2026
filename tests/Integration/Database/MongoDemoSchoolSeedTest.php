<?php
declare(strict_types=1);

use Saa\Database\DemoSchoolSeed;
use Saa\Database\Mongo;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$db = Mongo::database('saa_demo_test_' . bin2hex(random_bytes(8)));
$occupied = Mongo::database('saa_demo_occupied_' . bin2hex(random_bytes(8)));
$configured = Mongo::database();
$before = iterator_to_array($configured->listCollectionNames(), false);
$beforeCounts = [];
$beforeDocuments = [];
foreach ($before as $collection) {
    $beforeCounts[$collection] = $configured->selectCollection($collection)->countDocuments();
    $beforeDocuments[$collection] = json_encode(
        $configured->selectCollection($collection)->find([], ['sort' => ['_id' => 1]])->toArray(),
        JSON_THROW_ON_ERROR
    );
}
try {
    $counts = (new DemoSchoolSeed($db))->apply();
    foreach ($counts as $collection => $expected) {
        if ($db->selectCollection($collection)->countDocuments() !== $expected) {
            throw new RuntimeException('Contagem fictícia divergente: ' . $collection);
        }
    }
    foreach ($db->students->find() as $student) {
        if ($db->classes->countDocuments(['_id' => $student['class_id'], 'active' => true]) !== 1) {
            throw new RuntimeException('Aluno sem turma fictícia válida.');
        }
    }
    foreach ($db->student_guardians->find() as $link) {
        if ($db->students->countDocuments(['_id' => $link['student_id']]) !== 1
            || $db->guardians->countDocuments(['_id' => $link['guardian_id']]) !== 1) {
            throw new RuntimeException('Vínculo fictício inválido.');
        }
    }
    $expectedLinks = ['DEMO-2026-001' => 'maria.almeida@example.test',
        'DEMO-2026-002' => 'joao.souza@example.test',
        'DEMO-2026-003' => null, 'DEMO-2026-004' => 'ana.costa@example.test',
        'DEMO-2026-005' => null];
    foreach ($db->students->find() as $student) {
        $links = $db->student_guardians->find(['student_id' => $student['_id']])->toArray();
        $actual = count($links) === 1
            ? $db->guardians->findOne(['_id' => $links[0]['guardian_id']])['email'] : null;
        if (count($links) > 1 || $actual !== $expectedLinks[$student['registration_no']]) {
            throw new RuntimeException('Vínculo fictício inesperado.');
        }
    }
    if ($db->users->countDocuments() !== 0 || $db->absences->countDocuments() !== 0
        || $db->attendance_sessions->countDocuments() !== 0
        || $db->communications->countDocuments() !== 0 || $db->justifications->countDocuments() !== 0
        || $db->operation_logs->countDocuments() !== 0
        || $db->mail_queue->countDocuments() !== 0 || $db->push_queue->countDocuments() !== 0
        || $db->push_subscriptions->countDocuments() !== 0 || $db->push_invites->countDocuments() !== 0
        || $db->students->countDocuments(['registration_no' => ['$not' => ['$regex' => '^DEMO-']]]) !== 0
        || $db->guardians->countDocuments(['email' => ['$not' => ['$regex' => '@example\\.test$']]]) !== 0) {
        throw new RuntimeException('Carga fictícia criou usuário, mensagem ou dados inesperados.');
    }
    try {
        (new DemoSchoolSeed($db))->apply();
        throw new LogicException('Reexecução não foi recusada.');
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), 'já inicializado')) { throw $error; }
    }
    foreach ($counts as $collection => $expected) {
        if ($db->selectCollection($collection)->countDocuments() !== $expected) {
            throw new RuntimeException('Reexecução alterou documentos fictícios.');
        }
    }
    $occupied->classes->insertOne(['_id' => new MongoDB\BSON\ObjectId(), 'unrelated' => true]);
    try {
        (new DemoSchoolSeed($occupied))->apply();
        throw new LogicException('Banco ocupado não foi recusado.');
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), 'já inicializado')) { throw $error; }
    }
    try {
        (new DemoSchoolSeed($configured))->apply();
        throw new LogicException('Banco operacional não foi recusado.');
    } catch (InvalidArgumentException $error) {
        if (!str_contains($error->getMessage(), 'diferente do banco operacional')) { throw $error; }
    }
    if (iterator_to_array($configured->listCollectionNames(), false) !== $before) {
        throw new RuntimeException('Banco configurado foi modificado.');
    }
    foreach ($beforeCounts as $collection => $count) {
        if ($configured->selectCollection($collection)->countDocuments() !== $count
            || json_encode($configured->selectCollection($collection)
                ->find([], ['sort' => ['_id' => 1]])->toArray(), JSON_THROW_ON_ERROR)
                !== $beforeDocuments[$collection]) {
            throw new RuntimeException('Documentos operacionais foram modificados.');
        }
    }
    fwrite(STDOUT, "MongoDemoSchoolSeedTest: isolamento, referências, contagens e ausência de filas passaram.\n");
} finally {
    $db->drop();
    $occupied->drop();
}
