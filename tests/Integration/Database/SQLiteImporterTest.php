<?php
declare(strict_types=1);

use Saa\Database\Mongo;
use Saa\Migration\SQLiteImporter;
use Saa\Migration\SQLiteSnapshot;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/src/Database.php';
require dirname(__DIR__, 3) . '/src/Migration/SQLiteSnapshot.php';

$directory = sys_get_temp_dir() . '/saa_import_' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$source = $directory . '/source.sqlite3';
$snapshot = $directory . '/snapshot.sqlite3';
$manifest = $directory . '/manifest.json';
$db = Mongo::database('saa_import_test_' . bin2hex(random_bytes(8)));
try {
    putenv('DATABASE_PATH=' . $source);
    putenv('SEED_DEMO=true');
    $pdo = Database::connect();
    Database::migrate($pdo);
    Database::seed($pdo);
    $now = Database::now();
    $pdo->prepare('INSERT INTO attendance_sessions(class_id, attendance_date, completed_by, completed_at) VALUES(1, ?, 1, ?)')
        ->execute(['2026-11-15', $now]);
    $pdo->prepare('INSERT INTO attendance_records(session_id, student_id, status, created_at) VALUES(1, 1, ?, ?)')
        ->execute(['AUSENTE', $now]);
    $pdo->prepare('INSERT INTO attendance_records(session_id, student_id, status, created_at) VALUES(1, 2, ?, ?)')
        ->execute(['PRESENTE', $now]);
    $pdo->prepare('INSERT INTO absences(student_id, class_id, attendance_session_id, absence_date, status, registered_by, created_at) VALUES(1, 1, 1, ?, ?, 1, ?)')
        ->execute(['2026-11-15', 'PENDENTE', $now]);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $pdo->prepare('INSERT INTO communications(absence_id, guardian_id, email_to, token_hash, status, created_at) VALUES(1, 1, ?, ?, ?, ?)')
        ->execute(['maria.almeida@example.test', hash('sha256', $token), 'PENDENTE', $now]);
    $pdo->prepare('INSERT INTO mail_queue(communication_id, recipient, subject, body, status, created_at) VALUES(1, ?, ?, ?, ?, ?)')
        ->execute(['maria.almeida@example.test', 'Comunicado', 'Link /justificar/' . $token, 'AGUARDANDO', $now]);
    $pdo->prepare('INSERT INTO operation_logs(user_id, action, entity, entity_id, details, happened_at) VALUES(1, ?, ?, ?, ?, ?)')
        ->execute(['CHAMADA_CONCLUIDA', 'chamada', '1', '', $now]);
    $pdo = null;
    $report = SQLiteSnapshot::prepare($source, $snapshot);
    if ($report['status'] !== 'ready_for_dry_run') { throw new RuntimeException('Prévia inesperada.'); }
    file_put_contents($manifest, json_encode($report, JSON_THROW_ON_ERROR));
    $importer = new SQLiteImporter($snapshot, $manifest, $db);
    $counts = $importer->run(false);
    if ($counts['attendance_records'] !== 2
        || iterator_to_array($db->listCollectionNames(), false) !== []) {
        throw new RuntimeException('Dry-run gravou no MongoDB ou contou errado.');
    }
    $importer->run(true);
    $importer->run(true); // Retomada da mesma cópia não duplica.
    $student = $db->students->findOne(['registration_no' => '2026-001']);
    $absence = $db->absences->findOne(['student_id' => $student['_id']]);
    $legacySession = $db->legacy_attendance_sessions->findOne();
    $queue = $db->mail_queue->findOne();
    if ($db->users->countDocuments() !== 3 || $db->students->countDocuments() !== 5
        || $db->legacy_attendance_records->countDocuments() !== 2
        || $db->attendance_sessions->countDocuments() !== 0
        || (string)$absence['legacy_attendance_session_id'] !== (string)$legacySession['_id']
        || $absence['attendance_session_id'] !== null || $absence['class_snapshot_source'] !== 'unknown'
        || $queue['status'] !== 'QUARENTENA' || $queue['legacy_status'] !== 'AGUARDANDO'
        || $db->migration_runs->findOne(['_id' => 'sqlite_v1'])['status'] !== 'IMPORTADO') {
        throw new RuntimeException('Importação, histórico ou quarentena divergente.');
    }
    $processed = (new Saa\Database\MongoMailQueue($db))->process(
        static function (): void { throw new RuntimeException('Mensagem histórica não pode ser enviada.'); }
    );
    if ($processed !== 0) { throw new RuntimeException('Fila histórica saiu da quarentena.'); }
    fwrite(STDOUT, "SQLiteImporterTest: dry-run, retomada, histórico e quarentena passaram.\n");
} finally {
    $db->drop();
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
