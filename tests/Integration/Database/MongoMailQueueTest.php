<?php
declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoMailQueue;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$db = Mongo::database('saa_mail_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $actor = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($actor, 'Turma', '5º', 2026, 'Manhã');
    $student = $directory->createStudent($actor, 'Aluno', null, $class);
    $directory->createGuardian($actor, 'Mãe', 'mae@example.org', 'Mãe', [$student]);
    (new MongoAttendance($db))->record($actor, $class, [(string)$student => 'AUSENTE'],
        '2026-11-15', 'http://127.0.0.1:8765');
    $worker = new MongoMailQueue($db);
    $attempts = 0;
    $failed = $worker->process(static function (array $job) use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('Não enviar');
    });
    $job = $db->mail_queue->findOne();
    if ($failed !== 1 || $attempts !== 1 || $job['status'] !== 'AGUARDANDO'
        || (int)$job['attempts'] !== 1 || !($job['next_attempt_at'] instanceof UTCDateTime)
        || $db->communications->countDocuments(['status' => 'PENDENTE']) !== 1) {
        throw new RuntimeException('Falha transitória não foi reagendada de forma atômica.');
    }
    if ($worker->process(static function (): void { throw new LogicException('Retry adiantado.'); }) !== 0) {
        throw new RuntimeException('Retry antes do prazo foi permitido.');
    }
    $db->mail_queue->updateOne(['_id' => $job['_id']], ['$set' => [
        'status' => 'PROCESSANDO', 'started_at' => new UTCDateTime((time() - 600) * 1000),
    ]]);
    $sent = [];
    if ($worker->process(static function (array $payload) use (&$sent): void { $sent[] = $payload; }) !== 1) {
        throw new RuntimeException('Reserva expirada não foi recuperada.');
    }
    $job = $db->mail_queue->findOne();
    if (count($sent) !== 1 || $sent[0]['recipient'] !== 'mae@example.org'
        || $job['status'] !== 'CONCLUIDO' || $job['body'] !== '' || (int)$job['attempts'] !== 2
        || $db->communications->countDocuments(['status' => 'ENVIADO']) !== 1
        || $worker->process(static function (): void { throw new LogicException('Reenvio indevido.'); }) !== 0) {
        throw new RuntimeException('Entrega, limpeza do corpo ou proteção de reenvio falhou.');
    }
    fwrite(STDOUT, "MongoMailQueueTest: retry, lease e entrega passaram.\n");
} finally {
    $db->drop();
}
