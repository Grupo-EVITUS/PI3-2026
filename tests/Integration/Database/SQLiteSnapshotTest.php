<?php
declare(strict_types=1);

use Saa\Migration\SQLiteSnapshot;

require dirname(__DIR__, 3) . '/src/Database.php';
require dirname(__DIR__, 3) . '/src/Migration/SQLiteSnapshot.php';

$directory = sys_get_temp_dir() . '/saa_snapshot_' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$source = $directory . '/source.sqlite3';
$snapshot = $directory . '/copy.sqlite3';
$broken = $directory . '/broken.sqlite3';
try {
    putenv('DATABASE_PATH=' . $source);
    putenv('SEED_DEMO=true');
    $pdo = Database::connect();
    Database::migrate($pdo);
    Database::seed($pdo);
    $before = hash_file('sha256', $source);
    $report = SQLiteSnapshot::prepare($source, $snapshot);
    if ($report['status'] !== 'ready_for_dry_run' || $report['counts']['users'] !== 3
        || $report['counts']['students'] !== 5 || $report['counts']['mail_queue'] !== 0
        || strlen($report['snapshot_sha256']) !== 64 || hash_file('sha256', $source) !== $before) {
        throw new RuntimeException('Cópia consistente ou inventário não passaram.');
    }
    try {
        SQLiteSnapshot::prepare($source, $snapshot);
        throw new RuntimeException('A cópia anterior foi sobrescrita.');
    } catch (InvalidArgumentException) {
    }
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->exec("INSERT INTO students(name, class_id, created_at) VALUES('Órfão', 9999, '2026-11-15T00:00:00Z')");
    $blocked = SQLiteSnapshot::prepare($source, $broken);
    if ($blocked['status'] !== 'blocked' || $blocked['foreign_key_failures'] !== 1
        || !in_array('foreign_keys', $blocked['problems'], true)) {
        throw new RuntimeException('Referência inválida não bloqueou o plano de importação.');
    }
    fwrite(STDOUT, "SQLiteSnapshotTest: cópia, inventário e bloqueio de órfãos passaram.\n");
} finally {
    $pdo = null;
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
