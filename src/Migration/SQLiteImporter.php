<?php
declare(strict_types=1);

namespace Saa\Migration;

use DateTimeImmutable;
use DateTimeZone;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use PDO;
use RuntimeException;
use Saa\Database\Collections;
use Saa\Database\Schema;

/** Importação retomável em banco Mongo dedicado; mensagens antigas nunca ficam enviáveis. */
final class SQLiteImporter
{
    private const ORDER = [
        'users' => Collections::USERS, 'classes' => Collections::CLASSES,
        'students' => Collections::STUDENTS, 'guardians' => Collections::GUARDIANS,
        'student_guardians' => Collections::STUDENT_GUARDIANS,
        'attendance_sessions' => Collections::LEGACY_ATTENDANCE_SESSIONS,
        'attendance_records' => Collections::LEGACY_ATTENDANCE_RECORDS,
        'absences' => Collections::ABSENCES, 'communications' => Collections::COMMUNICATIONS,
        'mail_queue' => Collections::MAIL_QUEUE, 'justifications' => Collections::JUSTIFICATIONS,
        'operation_logs' => Collections::OPERATION_LOGS,
    ];

    private string $sourceHash;
    private array $manifest;

    public function __construct(private readonly string $snapshot, string $manifestPath, private readonly Database $target)
    {
        $json = file_get_contents($manifestPath);
        $this->manifest = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
        $this->sourceHash = $this->manifest['snapshot_sha256'] ?? '';
        $inspection = SQLiteSnapshot::inspect($snapshot);
        if (($this->manifest['status'] ?? null) !== 'ready_for_dry_run'
            || $inspection['status'] !== 'ready_for_dry_run'
            || !preg_match('/^[a-f0-9]{64}$/', $this->sourceHash)
            || !hash_equals($this->sourceHash, hash_file('sha256', $snapshot) ?: '')
            || ($this->manifest['counts'] ?? null) !== $inspection['counts']) {
            throw new RuntimeException('Cópia e manifesto incompatíveis; gere nova cópia antes de importar.');
        }
    }

    /** @return array<string, int> */
    public function run(bool $apply = false): array
    {
        $counts = [];
        if ($apply) {
            $run = $this->target->migration_runs->findOne(['_id' => 'sqlite_v1']);
            if ($run === null) {
                if ($this->target->migration_runs->countDocuments() > 0) {
                    throw new RuntimeException('Destino contém outra execução de migração.');
                }
                foreach (self::ORDER as $collection) {
                    if ($this->target->selectCollection($collection)->countDocuments() > 0) {
                        throw new RuntimeException('Destino contém dados; use banco MongoDB novo e isolado.');
                    }
                }
                if ($this->target->attendance_sessions->countDocuments() > 0) {
                    throw new RuntimeException('Destino contém chamadas canônicas.');
                }
                $this->target->migration_runs->insertOne([
                    '_id' => 'sqlite_v1', 'source_sha256' => $this->sourceHash,
                    'status' => 'IMPORTANDO', 'created_at' => new UTCDateTime(),
                ]);
            } elseif (($run['source_sha256'] ?? null) !== $this->sourceHash) {
                throw new RuntimeException('Destino associado a uma cópia SQLite diferente.');
            }
            Schema::apply($this->target);
        }
        $pdo = new PDO('sqlite:' . realpath($this->snapshot));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        foreach (self::ORDER as $table => $collection) {
            $count = 0;
            foreach ($pdo->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'student_guardians' ? 'student_id, guardian_id' : 'id')) as $row) {
                $document = $this->convert($table, $row);
                if ($apply) {
                    $this->write($collection, $document);
                }
                $count++;
            }
            $counts[$table] = $count;
            if ($count !== $this->manifest['counts'][$table]) {
                throw new RuntimeException('Contagem divergente na origem: ' . $table);
            }
            if ($apply && $this->target->selectCollection($collection)->countDocuments() !== $count) {
                throw new RuntimeException('Contagem divergente no destino: ' . $collection);
            }
        }
        if ($apply) {
            $this->target->migration_runs->updateOne(
                ['_id' => 'sqlite_v1', 'source_sha256' => $this->sourceHash],
                ['$set' => ['status' => 'IMPORTADO', 'completed_at' => new UTCDateTime()]]
            );
        }
        return $counts;
    }

    private function write(string $collection, array $document): void
    {
        $target = $this->target->selectCollection($collection);
        $existing = $target->findOne(['_id' => $document['_id']]);
        if ($existing !== null) {
            if (($existing['legacy_source_sha256'] ?? null) !== $this->sourceHash
                || ($existing['legacy_id'] ?? null) !== $document['legacy_id']
                || json_encode($existing, JSON_THROW_ON_ERROR) !== json_encode($document, JSON_THROW_ON_ERROR)) {
                throw new RuntimeException('Colisão ou alteração no destino: ' . $collection);
            }
            return;
        }
        $target->insertOne($document);
    }

    private function convert(string $table, array $row): array
    {
        $key = $table === 'student_guardians'
            ? $row['student_id'] . ':' . $row['guardian_id'] : (string)$row['id'];
        $doc = ['_id' => $this->id($table, $key),
            'legacy_id' => $table === 'student_guardians' ? $key : (int)$row['id'],
            'legacy_source_sha256' => $this->sourceHash];
        switch ($table) {
            case 'users':
                return $doc + ['name' => $row['name'], 'email' => $row['email'],
                    'email_key' => Schema::emailKey($row['email']), 'password_hash' => $row['password_hash'],
                    'profile' => $row['profile'], 'active' => (bool)$row['active'],
                    'created_at' => $this->date($row['created_at'])];
            case 'classes':
                return $doc + ['name' => $row['name'], 'grade' => $row['grade'],
                    'academic_year' => (int)$row['academic_year'], 'shift' => $row['shift'],
                    'active' => (bool)$row['active'], 'created_at' => $this->date($row['created_at'])];
            case 'students':
                return $doc + ['name' => $row['name'], 'registration_no' => $row['registration_no'],
                    'class_id' => $this->id('classes', $row['class_id']), 'active' => (bool)$row['active'],
                    'created_at' => $this->date($row['created_at'])];
            case 'guardians':
                return $doc + ['name' => $row['name'], 'email' => $row['email'],
                    'relationship' => $row['relationship'], 'active' => (bool)$row['active'],
                    'created_at' => $this->date($row['created_at'])];
            case 'student_guardians':
                return $doc + ['student_id' => $this->id('students', $row['student_id']),
                    'guardian_id' => $this->id('guardians', $row['guardian_id'])];
            case 'attendance_sessions':
                return $doc + ['class_id' => $this->id('classes', $row['class_id']),
                    'attendance_date' => $row['attendance_date'],
                    'completed_by' => $this->id('users', $row['completed_by']),
                    'completed_at' => $this->date($row['completed_at'])];
            case 'attendance_records':
                return $doc + ['session_id' => $this->id('attendance_sessions', $row['session_id']),
                    'student_id' => $this->id('students', $row['student_id']),
                    'status' => $row['status'], 'created_at' => $this->date($row['created_at'])];
            case 'absences':
                return $doc + ['student_id' => $this->id('students', $row['student_id']),
                    'class_id' => $this->nullableId('classes', $row['class_id']),
                    'attendance_session_id' => null,
                    'legacy_attendance_session_id' => $this->nullableId('attendance_sessions', $row['attendance_session_id']),
                    'absence_date' => $row['absence_date'], 'status' => $row['status'],
                    'registered_by' => $this->id('users', $row['registered_by']),
                    'created_at' => $this->date($row['created_at']), 'class_snapshot_source' => 'unknown'];
            case 'communications':
                return $doc + ['absence_id' => $this->id('absences', $row['absence_id']),
                    'guardian_id' => $this->id('guardians', $row['guardian_id']),
                    'email_to' => $row['email_to'], 'token_hash' => $row['token_hash'],
                    'status' => $row['status'], 'sent_at' => $this->date($row['sent_at']),
                    'failure_detail' => $row['failure_detail'], 'expires_at' => $this->date($row['expires_at']),
                    'retry_of_id' => $this->nullableId('communications', $row['retry_of_id']),
                    'created_at' => $this->date($row['created_at'])];
            case 'mail_queue':
                return $doc + ['communication_id' => $this->id('communications', $row['communication_id']),
                    'recipient' => $row['recipient'], 'subject' => $row['subject'], 'body' => $row['body'],
                    'status' => 'QUARENTENA', 'legacy_status' => $row['status'],
                    'created_at' => $this->date($row['created_at']),
                    'started_at' => $this->date($row['started_at']),
                    'processed_at' => $this->date($row['processed_at'])];
            case 'justifications':
                return $doc + ['absence_id' => $this->id('absences', $row['absence_id']),
                    'communication_id' => $this->id('communications', $row['communication_id']),
                    'guardian_id' => $this->id('guardians', $row['guardian_id']),
                    'reason' => $row['reason'], 'explanation' => $row['explanation'],
                    'submitted_at' => $this->date($row['submitted_at'])];
            case 'operation_logs':
                return $doc + ['user_id' => $this->nullableId('users', $row['user_id']),
                    'action' => $row['action'], 'entity' => $row['entity'],
                    'entity_id' => $row['entity_id'], 'details' => $row['details'],
                    'happened_at' => $this->date($row['happened_at'])];
        }
        throw new RuntimeException('Tabela não mapeada.');
    }

    private function id(string $table, int|string $legacyId): ObjectId
    {
        return new ObjectId(substr(hash('sha256', $this->sourceHash . ':' . $table . ':' . $legacyId), 0, 24));
    }

    private function nullableId(string $table, int|string|null $legacyId): ?ObjectId
    {
        return $legacyId === null ? null : $this->id($table, $legacyId);
    }

    private function date(?string $value): ?UTCDateTime
    {
        if ($value === null) { return null; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new RuntimeException('Data UTC inválida na cópia.');
        }
        return new UTCDateTime($date);
    }
}
