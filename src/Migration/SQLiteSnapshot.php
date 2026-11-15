<?php
declare(strict_types=1);

namespace Saa\Migration;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Cópia consistente e inspeção sem importar dados nem disparar mensagens. */
final class SQLiteSnapshot
{
    private const TABLES = [
        'users', 'classes', 'students', 'guardians', 'student_guardians',
        'attendance_sessions', 'attendance_records', 'absences',
        'communications', 'mail_queue', 'justifications', 'operation_logs',
    ];

    public static function prepare(string $sourcePath, string $snapshotPath): array
    {
        $source = realpath($sourcePath);
        $destinationDir = realpath(dirname($snapshotPath));
        if ($source === false || !is_file($source) || $destinationDir === false
            || !is_dir($destinationDir) || is_file($snapshotPath) || is_link($snapshotPath)
            || file_exists($snapshotPath)) {
            throw new InvalidArgumentException('Origem ou destino da cópia inválido; a cópia existente nunca é substituída.');
        }
        $destination = $destinationDir . DIRECTORY_SEPARATOR . basename($snapshotPath);
        if ($source === $destination) {
            throw new InvalidArgumentException('Origem e destino não podem ser iguais.');
        }
        $pdo = self::connect($source);
        try {
            // VACUUM INTO lê uma visão consistente mesmo com WAL; não atualiza o banco de origem.
            $pdo->exec("VACUUM INTO '" . str_replace("'", "''", $destination) . "'");
            $report = self::inspect($destination);
            $report['snapshot_sha256'] = hash_file('sha256', $destination);
            if ($report['snapshot_sha256'] === false) {
                throw new RuntimeException('Não foi possível calcular o hash da cópia.');
            }
            return $report;
        } catch (\Throwable $error) {
            // Uma cópia parcial nunca pode ser confundida com um backup aprovado.
            if (is_file($destination)) {
                unlink($destination);
            }
            throw $error;
        }
    }

    public static function inspect(string $snapshotPath): array
    {
        $snapshot = realpath($snapshotPath);
        if ($snapshot === false || !is_file($snapshot)) {
            throw new InvalidArgumentException('Cópia SQLite não encontrada.');
        }
        $pdo = self::connect($snapshot);
        $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $missing = [];
        foreach (self::TABLES as $table) {
            $exists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $exists->execute([$table]);
            if (!$exists->fetchColumn()) {
                $missing[] = $table;
            }
        }
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = in_array($table, $missing, true) ? null
                : (int)$pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        }
        $foreignKeyFailures = $integrity === 'ok' ? $pdo->query('PRAGMA foreign_key_check')->fetchAll() : [];
        $migrations = [];
        $migrationTable = $pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn();
        if ($migrationTable) {
            $migrations = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
        }
        $requiredMigrations = ['001_initial.sql', '002_absence_class_snapshot.sql', '003_complete_attendance.sql'];
        $invalid = [];
        if ($missing === []) {
            $invalid['absence_dates'] = self::invalidDays($pdo, 'absences', 'absence_date');
            $invalid['attendance_dates'] = self::invalidDays($pdo, 'attendance_sessions', 'attendance_date');
            $invalid['absence_session_links'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM absences a JOIN attendance_sessions t ON t.id = a.attendance_session_id '
                . 'WHERE a.class_id != t.class_id OR a.absence_date != t.attendance_date'
            )->fetchColumn();
            $invalid['session_roster'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM attendance_sessions t WHERE NOT EXISTS '
                . '(SELECT 1 FROM attendance_records r WHERE r.session_id = t.id)'
            )->fetchColumn();
            $invalid['status_projection'] = (int)$pdo->query(
                'SELECT COUNT(*) FROM attendance_records r JOIN attendance_sessions t ON t.id = r.session_id '
                . 'LEFT JOIN absences a ON a.attendance_session_id = r.session_id AND a.student_id = r.student_id '
                . "WHERE (r.status = 'AUSENTE' AND a.id IS NULL) OR (r.status = 'PRESENTE' AND a.id IS NOT NULL)"
            )->fetchColumn();
        }
        $queue = [];
        if ($counts['mail_queue'] !== null) {
            foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM mail_queue GROUP BY status') as $row) {
                $queue[$row['status']] = (int)$row['total'];
            }
        }
        $problems = [];
        if ($integrity !== 'ok') { $problems[] = 'integrity_check'; }
        if ($missing !== []) { $problems[] = 'missing_tables'; }
        if ($foreignKeyFailures !== []) { $problems[] = 'foreign_keys'; }
        if (array_diff($requiredMigrations, $migrations) !== []) { $problems[] = 'schema_migrations'; }
        foreach ($invalid as $name => $count) {
            if ($count > 0) { $problems[] = $name; }
        }
        return [
            'status' => $problems === [] ? 'ready_for_dry_run' : 'blocked',
            'source_format' => 'sqlite', 'schema_migrations' => $migrations,
            'counts' => $counts, 'queue_status_counts' => $queue,
            'foreign_key_failures' => count($foreignKeyFailures), 'invalid_counts' => $invalid,
            'problems' => $problems,
            'notes' => [
                'Filas antigas serão colocadas em QUARENTENA no importador; este comando não envia e-mails.',
                'Presenças anteriores à chamada completa não são inferidas.',
                'Snapshots de turma antigos podem refletir a turma na época da migração 002.',
            ],
        ];
    }

    private static function connect(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    private static function invalidDays(PDO $pdo, string $table, string $column): int
    {
        $invalid = 0;
        foreach ($pdo->query('SELECT ' . $column . ' FROM ' . $table) as $row) {
            $day = $row[$column];
            try {
                $date = is_string($day) ? DateTimeImmutable::createFromFormat('!Y-m-d', $day) : false;
            } catch (\ValueError) {
                $date = false;
            }
            if ($date === false || $date->format('Y-m-d') !== $day) { $invalid++; }
        }
        return $invalid;
    }
}
