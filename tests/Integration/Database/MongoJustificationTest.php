<?php
declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoJustification;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$db = Mongo::database('saa_justification_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $actor = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($actor, 'Turma inicial', '5º', 2026, 'Manhã');
    $student = $directory->createStudent($actor, 'Aluno', null, $class);
    $directory->createGuardian($actor, 'Mãe', 'mae@example.org', 'Mãe', [$student]);
    $directory->createGuardian($actor, 'Pai', 'pai@example.org', 'Pai', [$student]);
    (new MongoAttendance($db))->record($actor, $class, [(string)$student],
        '2026-11-15', 'https://escola.example.org', [(string)$student]);
    $service = new MongoJustification($db);
    $tokens = [];
    foreach ($db->mail_queue->find() as $job) {
        preg_match('~/justificar/([A-Za-z0-9_-]+)~', $job['body'], $matches);
        $tokens[] = $matches[1];
    }
    if (count($tokens) !== 2 || $service->find($tokens[0]) !== null || $service->find('invalid') !== null) {
        throw new RuntimeException('Token não enviado não deve abrir o formulário.');
    }
    $db->communications->updateMany([], ['$set' => ['status' => 'ENVIADO', 'sent_at' => new UTCDateTime()]]);
    $directory->updateClass($actor, $class, 'Turma renomeada', '5º', 2026, 'Tarde');
    $view = $service->find($tokens[0]);
    if ($view === null || $view['student_name'] !== 'Aluno' || $view['class_name'] !== 'Turma inicial') {
        throw new RuntimeException('O link deve usar o snapshot histórico da turma.');
    }
    try {
        $service->submit($tokens[0], 'OUTRO', '');
        throw new RuntimeException('Motivo vazio foi aceito.');
    } catch (InvalidArgumentException) {
    }
    $service->submit($tokens[0], 'DOENCA', '');
    try {
        $service->submit($tokens[1], 'TRANSPORTE', '');
        throw new RuntimeException('Uma segunda justificativa foi aceita.');
    } catch (DomainException) {
    }
    if ($db->absences->countDocuments(['status' => 'RESPONDIDA']) !== 1
        || $db->justifications->countDocuments(['reason' => 'DOENCA']) !== 1
        || $db->operation_logs->countDocuments(['action' => 'JUSTIFICATIVA_ENVIADA']) !== 1) {
        throw new RuntimeException('Ausência, justificativa e auditoria divergem.');
    }
    fwrite(STDOUT, "MongoJustificationTest: token, snapshot e resposta única passaram.\n");
} finally {
    $db->drop();
}
