<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoOccurrences;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function expectThrows(string $class, callable $call, string $message): void
{
    try {
        $call();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException($message . ' (lançou ' . $error::class . ': ' . $error->getMessage() . ')');
    }
    throw new RuntimeException($message);
}

$db = Mongo::database('saa_occurrences_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $actor = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    (new MongoStaffRegistration($db))->register($actor, 'Diretor', 'diretor@example.org', 'Senha-temporaria-2026', 'DIRETOR');
    $director = $db->users->findOne(['email' => 'diretor@example.org'])['_id'];

    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($actor, 'Turma inicial', '5º', 2026, 'Manhã');
    $alice = $directory->createStudent($actor, 'Alice', null, $class);
    $bruno = $directory->createStudent($actor, 'Bruno', null, $class);
    $directory->createGuardian($actor, 'Mãe da Alice', 'mae@example.org', 'Mãe', [$alice]);
    $directory->createGuardian($actor, 'Pai do Bruno', 'pai@example.org', 'Pai', [$bruno]);
    $directory->createGuardian($actor, 'Mãe do Bruno', 'maeb@example.org', 'Mãe', [$bruno]);
    (new MongoAttendance($db))->record($actor, $class, [(string)$alice => 'AUSENTE', (string)$bruno => 'AUSENTE'],
        '2026-11-15', 'https://escola.example.org');

    $service = new MongoOccurrences($db);
    $rows = $service->list();
    $byStudent = [];
    foreach ($rows as $row) {
        $byStudent[$row['student_name']] = $row;
    }
    if (count($rows) !== 2 || $byStudent['Alice']['communication_count'] !== 1
        || $byStudent['Bruno']['communication_count'] !== 2 || $byStudent['Alice']['failed_count'] !== 0
        || $byStudent['Alice']['status'] !== 'PENDENTE' || $byStudent['Alice']['reason'] !== null) {
        throw new RuntimeException('A listagem não reflete ausências e comunicações.');
    }
    if (count($service->list('PENDENTE')) !== 2 || $service->list('RESPONDIDA') !== [] || $service->list('CANCELADA') !== []) {
        throw new RuntimeException('O filtro por status falhou.');
    }
    expectThrows(InvalidArgumentException::class, fn() => $service->list('$ne'), 'Status arbitrário foi aceito.');

    // A turma exibida é a da chamada, mesmo após renomear a turma.
    $directory->updateClass($actor, $class, 'Turma renomeada', '6º', 2026, 'Tarde');
    if ($service->list()[0]['class_name'] !== 'Turma inicial') {
        throw new RuntimeException('A listagem deve usar o snapshot da turma.');
    }

    $aliceAbsence = new ObjectId($byStudent['Alice']['id']);
    $brunoAbsence = new ObjectId($byStudent['Bruno']['id']);
    $detail = $service->detail($aliceAbsence);
    if ($detail === null || $detail['student_name'] !== 'Alice' || $detail['class_name'] !== 'Turma inicial'
        || $detail['registered_by_name'] !== 'Coordenação' || count($detail['communications']) !== 1
        || $detail['communications'][0]['guardian_name'] !== 'Mãe da Alice' || $detail['justification'] !== null) {
        throw new RuntimeException('O detalhe da ausência está incorreto.');
    }
    if ($service->detail(new ObjectId()) !== null) {
        throw new RuntimeException('Ausência inexistente deve retornar nulo.');
    }

    // Reenvio: só para comunicação com falha.
    $aliceCommunication = $db->communications->findOne(['absence_id' => $aliceAbsence]);
    expectThrows(InvalidArgumentException::class,
        fn() => $service->retry($actor, $aliceCommunication['_id'], 'https://escola.example.org'),
        'Reenvio de comunicação sem falha foi aceito.');
    $db->communications->updateOne(['_id' => $aliceCommunication['_id']],
        ['$set' => ['status' => 'FALHA', 'failure_detail' => 'Falha na entrega SMTP.']]);
    $failedRow = array_values(array_filter($service->list(), fn($r) => $r['student_name'] === 'Alice'))[0];
    if ($failedRow['failed_count'] !== 1) {
        throw new RuntimeException('A falha não aparece na listagem.');
    }
    expectThrows(RuntimeException::class,
        fn() => $service->retry($director, $aliceCommunication['_id'], 'https://escola.example.org'),
        'O Diretor conseguiu reenviar.');
    expectThrows(InvalidArgumentException::class,
        fn() => $service->retry($actor, $aliceCommunication['_id'], 'ftp://escola.example.org'),
        'Endereço público inválido foi aceito.');
    if ($db->communications->countDocuments(['retry_of_id' => ['$exists' => true]]) !== 0) {
        throw new RuntimeException('Tentativas recusadas não podem criar comunicação.');
    }

    $returned = $service->retry($actor, $aliceCommunication['_id'], 'https://escola.example.org/');
    if ((string)$returned !== (string)$aliceAbsence) {
        throw new RuntimeException('O reenvio deve devolver a ocorrência.');
    }
    $new = $db->communications->findOne(['retry_of_id' => $aliceCommunication['_id']]);
    $job = $db->mail_queue->findOne(['communication_id' => $new['_id']]);
    if ($new === null || $new['status'] !== 'PENDENTE' || $new['channel'] !== 'EMAIL'
        || $new['token_hash'] === $aliceCommunication['token_hash'] || $job === null
        || $job['status'] !== 'AGUARDANDO' || $job['recipient'] !== 'mae@example.org'
        || preg_match('~^https://escola\.example\.org/justificar/[A-Za-z0-9_-]{43}$~m', $job['body']) !== 1) {
        throw new RuntimeException('O reenvio não criou comunicação e e-mail na fila.');
    }
    preg_match('~/justificar/([A-Za-z0-9_-]{43})~', $job['body'], $token);
    if (hash('sha256', $token[1]) !== $new['token_hash']) {
        throw new RuntimeException('O link do reenvio não corresponde ao token guardado.');
    }
    $log = $db->operation_logs->findOne(['action' => 'EMAIL_REENVIO_SOLICITADO']);
    if ($log === null || $log['user_id'] != $actor || $log['entity_id'] !== (string)$new['_id']
        || str_contains($log['details'], 'example.org') || str_contains($log['details'], $token[1])) {
        throw new RuntimeException('A auditoria do reenvio está incorreta ou expõe dados sensíveis.');
    }
    // Reenvio duplicado e contagem de falhas pendentes.
    expectThrows(DomainException::class,
        fn() => $service->retry($actor, $aliceCommunication['_id'], 'https://escola.example.org'),
        'A mesma falha foi reenviada duas vezes.');
    $after = $service->detail($aliceAbsence);
    if (count($after['communications']) !== 2 || !$after['communications'][0]['retried']
        || !$after['communications'][1]['is_retry']
        || array_values(array_filter($service->list(), fn($r) => $r['student_name'] === 'Alice'))[0]['failed_count'] !== 0) {
        throw new RuntimeException('O estado após o reenvio está incorreto.');
    }

    // Ocorrência respondida não é reenviada; a justificativa aparece na lista e no detalhe.
    $brunoCommunication = $db->communications->findOne(['absence_id' => $brunoAbsence]);
    $db->communications->updateOne(['_id' => $brunoCommunication['_id']], ['$set' => ['status' => 'FALHA']]);
    $db->absences->updateOne(['_id' => $brunoAbsence], ['$set' => ['status' => 'RESPONDIDA']]);
    $db->justifications->insertOne([
        'absence_id' => $brunoAbsence, 'communication_id' => $brunoCommunication['_id'],
        'guardian_id' => $brunoCommunication['guardian_id'], 'reason' => 'OUTRO',
        'explanation' => 'Viagem em família', 'submitted_at' => new UTCDateTime(),
    ]);
    expectThrows(InvalidArgumentException::class,
        fn() => $service->retry($actor, $brunoCommunication['_id'], 'https://escola.example.org'),
        'Reenvio de ocorrência respondida foi aceito.');
    $answered = $service->list('RESPONDIDA');
    if (count($answered) !== 1 || $answered[0]['student_name'] !== 'Bruno' || $answered[0]['reason'] !== 'OUTRO'
        || $answered[0]['explanation'] !== 'Viagem em família' || $answered[0]['failed_count'] !== 1) {
        throw new RuntimeException('A justificativa não aparece na listagem.');
    }
    $brunoDetail = $service->detail($brunoAbsence);
    if ($brunoDetail['justification']['reason'] !== 'OUTRO'
        || !in_array($brunoDetail['justification']['guardian_name'], ['Pai do Bruno', 'Mãe do Bruno'], true)
        || $brunoDetail['justification']['submitted_at'] === null) {
        throw new RuntimeException('O detalhe não mostra a justificativa.');
    }

    // Filtros por turma, aluno e período (dia da ausência, extremos inclusivos).
    $classB = $directory->createClass($actor, 'Turma B', '6º', 2026, 'Tarde');
    $carla = $directory->createStudent($actor, 'Carla', null, $classB);
    $directory->createGuardian($actor, 'Mãe da Carla', 'maec@example.org', 'Mãe', [$carla]);
    $attendance = new MongoAttendance($db);
    $attendance->record($actor, $classB, [(string)$carla => 'AUSENTE'], '2026-11-20', 'https://escola.example.org');
    $attendance->record($actor, $class, [(string)$alice => 'AUSENTE', (string)$bruno => 'PRESENTE'],
        '2026-11-18', 'https://escola.example.org');
    $summary = static fn(array $rows): array => array_map(
        static fn(array $r): string => $r['student_name'] . ':' . $r['absence_date'], $rows
    );
    if ($summary($service->list())[0] !== 'Carla:2026-11-20' || $summary($service->list())[1] !== 'Alice:2026-11-18'
        || count($service->list()) !== 4) {
        throw new RuntimeException('A listagem completa deve vir da data mais recente para a mais antiga.');
    }
    $countOf = static fn(array $rows): int => count($rows);
    $checks = [
        'turma A' => [$countOf($service->list(classId: $class)), 3],
        'turma B' => [$summary($service->list(classId: $classB)), ['Carla:2026-11-20']],
        'aluno' => [$countOf($service->list(studentId: $alice)), 2],
        'a partir de' => [$summary($service->list(from: '2026-11-16')), ['Carla:2026-11-20', 'Alice:2026-11-18']],
        'até' => [$countOf($service->list(to: '2026-11-15')), 2],
        'um dia' => [$summary($service->list(from: '2026-11-18', to: '2026-11-18')), ['Alice:2026-11-18']],
        'respondidas da turma A' => [$summary($service->list('RESPONDIDA', $class)), ['Bruno:2026-11-15']],
        'combinado' => [$summary($service->list('PENDENTE', $class, $alice, '2026-11-16')), ['Alice:2026-11-18']],
        'sem resultado' => [$service->list(classId: $classB, studentId: $alice), []],
    ];
    foreach ($checks as $label => [$actual, $expected]) {
        if ($actual !== $expected) {
            throw new RuntimeException('Filtro "' . $label . '" retornou ' . json_encode($actual) . '.');
        }
    }
    foreach ([['2026-11-20', '2026-11-01'], ['2026-02-30', null], ['abc', null], [null, '2026-13-01']] as [$from, $to]) {
        expectThrows(InvalidArgumentException::class, fn() => $service->list(from: $from, to: $to),
            'Período inválido foi aceito: ' . json_encode([$from, $to]));
    }
    fwrite(STDOUT, "MongoOccurrencesTest: lista, filtros, detalhe, snapshot e reenvio passaram.\n");
} finally {
    $db->drop();
}
