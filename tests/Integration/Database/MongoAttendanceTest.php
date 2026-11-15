<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function assertAttendance(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function failsAttendance(callable $operation, string $exception): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        assertAttendance($error instanceof $exception, 'Tipo de falha inesperado: ' . get_class($error));
        return;
    }
    throw new RuntimeException('A operação inválida foi aceita.');
}

$db = Mongo::database('saa_attendance_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $actor = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $directory = new MongoSchoolDirectory($db);
    $attendance = new MongoAttendance($db);
    $class = $directory->createClass($actor, 'Turma A', '5º ano', 2026, 'Manhã');
    $present = $directory->createStudent($actor, 'Presente', 'MAT-001', $class);
    $absent = $directory->createStudent($actor, 'Ausente', 'MAT-002', $class);
    $noGuardian = $directory->createStudent($actor, 'Sem responsável', null, $class);
    $directory->createGuardian($actor, 'Responsável 1', 'primeiro@example.org', 'Mãe', [$absent]);
    $directory->createGuardian($actor, 'Responsável 2', 'segundo@example.org', 'Pai', [$absent]);
    $statuses = [(string)$present => 'PRESENTE', (string)$absent => 'AUSENTE',
        (string)$noGuardian => 'AUSENTE'];

    failsAttendance(fn() => $attendance->record($actor, $class,
        [(string)$present => 'PRESENTE'], '2026-11-15', 'https://escola.example.org'), InvalidArgumentException::class);
    failsAttendance(fn() => $attendance->record($actor, $class,
        $statuses + [(string)new ObjectId() => 'PRESENTE'], '2026-11-15', 'https://escola.example.org'),
        InvalidArgumentException::class);
    failsAttendance(fn() => $attendance->record($actor, $class,
        $statuses, '2026-02-30', 'https://escola.example.org'), InvalidArgumentException::class);
    failsAttendance(fn() => $attendance->record($actor, $class,
        $statuses, '2026-11-15', 'https://user@escola.example.org'), InvalidArgumentException::class);
    failsAttendance(fn() => $attendance->record(new ObjectId(), $class,
        $statuses, '2026-11-15', 'https://escola.example.org'), RuntimeException::class);
    assertAttendance($db->attendance_sessions->countDocuments() === 0, 'Rejeições não podem gerar sessão.');

    $result = $attendance->record($actor, $class, $statuses, '2026-11-15', 'https://escola.example.org');
    assertAttendance($result['attendance_counts'] === ['present' => 1, 'absent' => 2, 'total' => 3]
        && $result['notifications'] === 2 && $result['without_guardians'] === ['Sem responsável'],
        'Resumo da chamada divergente.');
    $sessionId = new ObjectId($result['attendance_session_id']);
    $saved = $db->attendance_sessions->findOne(['_id' => $sessionId]);
    assertAttendance($saved['status'] === 'CONCLUIDA' && count($saved['entries']) === 3
        && $saved['class_name'] === 'Turma A', 'Roster e snapshot precisam ser persistidos.');
    assertAttendance($db->absences->countDocuments(['attendance_session_id' => $sessionId]) === 2
        && $db->absences->countDocuments(['student_id' => $present]) === 0,
        'Somente alunos ausentes geram ocorrência.');
    assertAttendance($db->communications->countDocuments() === 2 && $db->mail_queue->countDocuments() === 2,
        'Cada responsável ativo recebe uma comunicação na fila.');
    foreach ($db->mail_queue->find() as $queue) {
        $communication = $db->communications->findOne(['_id' => $queue['communication_id']]);
        preg_match('~/justificar/([A-Za-z0-9_-]+)~', $queue['body'], $matches);
        assertAttendance($queue['status'] === 'AGUARDANDO' && $communication !== null
            && isset($matches[1]) && hash('sha256', $matches[1]) === $communication['token_hash'],
            'Token da fila deve corresponder apenas ao hash persistido na comunicação.');
    }
    assertAttendance($db->operation_logs->countDocuments(['action' => 'CHAMADA_CONCLUIDA',
        'entity_id' => (string)$sessionId]) === 1, 'Chamada deve gerar auditoria.');
    failsAttendance(fn() => $attendance->record($actor, $class, $statuses, '2026-11-15',
        'https://escola.example.org'), DomainException::class);
    assertAttendance($db->mail_queue->countDocuments() === 2, 'Chamada duplicada não pode duplicar e-mails.');

    $presentOnlyClass = $directory->createClass($actor, 'Turma B', '6º ano', 2026, 'Tarde');
    $onlyPresent = $directory->createStudent($actor, 'Sempre presente', null, $presentOnlyClass);
    $aoe = (new MongoStaffRegistration($db))->register(
        $actor, 'Agente escolar', 'aoe@example.org', 'Senha-temporaria-2026', 'AOE'
    );
    $allPresent = $attendance->record($aoe, $presentOnlyClass,
        [(string)$onlyPresent => 'PRESENTE'], '2026-11-15', 'https://escola.example.org');
    assertAttendance($allPresent['notifications'] === 0 && $db->mail_queue->countDocuments() === 2,
        'Turma sem faltas não gera e-mail.');

    $legacyClass = $directory->createClass($actor, 'Turma legado', '8º ano', 2026, 'Manhã');
    $legacyStudent = $directory->createStudent($actor, 'Falta antiga', null, $legacyClass);
    $db->absences->insertOne(['_id' => new ObjectId(), 'student_id' => $legacyStudent,
        'class_id' => $legacyClass, 'absence_date' => '2026-11-15', 'status' => 'PENDENTE',
        'registered_by' => $actor, 'created_at' => new UTCDateTime(),
        'class_snapshot_source' => 'unknown']);
    failsAttendance(fn() => $attendance->record($actor, $legacyClass,
        [(string)$legacyStudent => 'AUSENTE'], '2026-11-15', 'https://escola.example.org'),
        DomainException::class);
    assertAttendance($db->attendance_sessions->countDocuments(['class_id' => $legacyClass]) === 0
        && $db->mail_queue->countDocuments() === 2,
        'Falta antiga não pode gerar segunda sessão nem segundo e-mail.');

    // Uma falha real depois de inserir a sessão deve abortar toda a transação.
    $rollbackClass = $directory->createClass($actor, 'Turma C', '7º ano', 2026, 'Noite');
    $rollbackStudent = $directory->createStudent($actor, 'Teste rollback', null, $rollbackClass);
    $db->command(['collMod' => 'operation_logs', 'validator' => ['action' => 'impossible'],
        'validationLevel' => 'strict', 'validationAction' => 'error'])->toArray();
    failsAttendance(fn() => $attendance->record($actor, $rollbackClass,
        [(string)$rollbackStudent => 'AUSENTE'], '2026-11-15', 'https://escola.example.org'),
        BulkWriteException::class);
    assertAttendance($db->attendance_sessions->countDocuments(['class_id' => $rollbackClass]) === 0
        && $db->absences->countDocuments(['student_id' => $rollbackStudent]) === 0,
        'Falha da auditoria deve reverter sessão e ocorrência.');
    fwrite(STDOUT, "MongoAttendanceTest: roster, ausências, fila, auditoria e rollback passaram.\n");
} finally {
    $db->drop();
}
