<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $call, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$db = Mongo::database('saa_fields_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $actor = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($actor, '5º A', '5º ano', 2026, 'Manhã');

    // Data de nascimento do aluno: opcional, validada, editável e removível.
    $ana = $directory->createStudent($actor, 'Ana', 'M-1', $class, '2014-03-05');
    $bia = $directory->createStudent($actor, 'Bia', 'M-2', $class);
    $cai = $directory->createStudent($actor, 'Caio', 'M-3', $class, '   ');
    check($db->students->findOne(['_id' => $ana])['birth_date'] === '2014-03-05', 'A data de nascimento não foi gravada.');
    check($db->students->findOne(['_id' => $bia])['birth_date'] === null
        && $db->students->findOne(['_id' => $cai])['birth_date'] === null, 'Data ausente ou em branco deve ficar nula.');
    $before = $db->students->countDocuments();
    foreach (['05/03/2014', '2014-02-30', '2014-13-01', '1899-12-31', date('Y-m-d', time() + 86400 * 2), 'abc', "2014-03-05\n"] as $bad) {
        rejects(fn() => $directory->createStudent($actor, 'Invalido', null, $class, $bad), 'Data inválida aceita: ' . json_encode($bad));
        rejects(fn() => $directory->updateStudent($actor, $ana, 'Ana', 'M-1', $class, $bad), 'Edição aceitou data inválida: ' . json_encode($bad));
    }
    check($db->students->countDocuments() === $before
        && $db->students->findOne(['_id' => $ana])['birth_date'] === '2014-03-05',
        'Data inválida alterou ou criou cadastro.');
    $directory->createStudent($actor, 'Hoje', null, $class, date('Y-m-d'));
    $directory->updateStudent($actor, $ana, 'Ana', 'M-1', $class, '2013-12-31');
    check($db->students->findOne(['_id' => $ana])['birth_date'] === '2013-12-31', 'Edição da data não persistiu.');
    $directory->updateStudent($actor, $ana, 'Ana', 'M-1', $class, '');
    check($db->students->findOne(['_id' => $ana])['birth_date'] === null, 'A data não pôde ser removida.');
    check($db->operation_logs->countDocuments(['action' => 'ALUNO_ATUALIZADO', 'entity_id' => (string)$ana]) === 2,
        'Edições do aluno devem ser auditadas.');

    // Telefone do responsável: normalizado para dígitos, opcional, validado.
    $mae = $directory->createGuardian($actor, 'Mãe', 'mae@example.org', 'Mãe', [$ana], '(11) 91234-5678');
    $pai = $directory->createGuardian($actor, 'Pai', 'pai@example.org', 'Pai', [$ana], '+55 21 3456-7890');
    $tio = $directory->createGuardian($actor, 'Tio', 'tio@example.org', 'Tio', [$ana]);
    check($db->guardians->findOne(['_id' => $mae])['phone'] === '11912345678'
        && $db->guardians->findOne(['_id' => $pai])['phone'] === '2134567890'
        && $db->guardians->findOne(['_id' => $tio])['phone'] === null, 'Telefone não foi normalizado e gravado.');
    $before = $db->guardians->countDocuments();
    foreach (['abc', '12345', '(01) 91234-5678', '11 81234-5678', '+1 415 555 2671', '11912345678; x'] as $bad) {
        rejects(fn() => $directory->createGuardian($actor, 'Invalido', 'x@example.org', 'Pai', [$ana], $bad),
            'Telefone inválido aceito: ' . $bad);
        rejects(fn() => $directory->updateGuardian($actor, $mae, 'Mãe', 'mae@example.org', 'Mãe', [$ana], $bad),
            'Edição aceitou telefone inválido: ' . $bad);
    }
    check($db->guardians->countDocuments() === $before
        && $db->guardians->findOne(['_id' => $mae])['phone'] === '11912345678'
        && $db->student_guardians->countDocuments(['guardian_id' => $mae]) === 1,
        'Telefone inválido alterou ou criou cadastro.');
    $directory->updateGuardian($actor, $mae, 'Mãe', 'mae@example.org', 'Mãe', [$ana], '11 98888-7777');
    check($db->guardians->findOne(['_id' => $mae])['phone'] === '11988887777', 'Edição do telefone não persistiu.');
    $directory->updateGuardian($actor, $mae, 'Mãe', 'mae@example.org', 'Mãe', [$ana], null);
    check($db->guardians->findOne(['_id' => $mae])['phone'] === null, 'O telefone não pôde ser removido.');

    // O banco recusa formatos inválidos e aceita cadastros antigos, sem os campos novos.
    $now = new UTCDateTime();
    foreach ([
        ['students', ['name' => 'X', 'class_id' => $class, 'active' => true, 'created_at' => $now, 'birth_date' => '05/03/2014']],
        ['students', ['name' => 'X', 'class_id' => $class, 'active' => true, 'created_at' => $now, 'birth_date' => 20140305]],
        ['guardians', ['name' => 'X', 'email' => 'x@example.org', 'relationship' => 'Mãe', 'active' => true, 'created_at' => $now, 'phone' => '(11) 91234-5678']],
        ['guardians', ['name' => 'X', 'email' => 'x@example.org', 'relationship' => 'Mãe', 'active' => true, 'created_at' => $now, 'phone' => '123']],
    ] as [$collection, $document]) {
        try {
            $db->selectCollection($collection)->insertOne($document);
            throw new RuntimeException('O validator aceitou ' . $collection . ' com campo inválido.');
        } catch (BulkWriteException $error) {
            check(in_array(121, array_map(static fn($e) => $e->getCode(), $error->getWriteResult()->getWriteErrors()), true),
                'Código de rejeição inesperado.');
        }
    }
    $db->students->insertOne(['_id' => new ObjectId(), 'name' => 'Legado', 'class_id' => $class, 'active' => true, 'created_at' => $now]);
    $db->guardians->insertOne(['_id' => new ObjectId(), 'name' => 'Legado', 'email' => 'legado@example.org',
        'relationship' => 'Mãe', 'active' => true, 'created_at' => $now]);
    Schema::apply($db);  // reaplicar sobre dados antigos continua válido

    fwrite(STDOUT, "MongoRegistrationFieldsTest: nascimento e telefone passaram.\n");
} finally {
    $db->drop();
}
