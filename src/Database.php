<?php
declare(strict_types=1);

final class Database
{
    public static function connect(): PDO
    {
        $path = getenv('DATABASE_PATH') ?: __DIR__ . '/../var/saa.sqlite3';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório do banco de dados.');
        }

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    public static function migrate(PDO $pdo): void
    {
        $sql = file_get_contents(__DIR__ . '/../migrations/001_initial.sql');
        if ($sql === false) {
            throw new RuntimeException('Não foi possível ler a migração inicial.');
        }
        $pdo->exec($sql);
    }

    public static function seed(PDO $pdo): void
    {
        $enabled = strtolower(getenv('SEED_DEMO') ?: 'true') === 'true';
        if (!$enabled || (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $now = self::now();
            $users = [
                ['Ana AOE', 'aoe@escola.local', 'AOE12345!', 'AOE'],
                ['Carla Coordenação', 'coordenacao@escola.local', 'Coord12345!', 'COORDENACAO'],
                ['Daniel Diretor', 'diretor@escola.local', 'Diretor12345!', 'DIRETOR'],
            ];
            $insertUser = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, profile, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($users as [$name, $email, $password, $profile]) {
                $insertUser->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $profile, $now]);
            }

            $insertClass = $pdo->prepare(
                'INSERT INTO classes (name, grade, academic_year, shift, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $insertClass->execute(['5º ano A', '5º ano', 2026, 'Manhã', $now]);
            $morningClass = (int)$pdo->lastInsertId();
            $insertClass->execute(['7º ano B', '7º ano', 2026, 'Tarde', $now]);
            $afternoonClass = (int)$pdo->lastInsertId();

            $insertStudent = $pdo->prepare(
                'INSERT INTO students (name, registration_no, class_id, created_at) VALUES (?, ?, ?, ?)'
            );
            $students = [
                ['Lucas Almeida', '2026-001', $morningClass],
                ['Marina Souza', '2026-002', $morningClass],
                ['Pedro Santos', '2026-003', $morningClass],
                ['Julia Costa', '2026-004', $afternoonClass],
                ['Rafael Lima', '2026-005', $afternoonClass],
            ];
            foreach ($students as [$name, $registration, $classId]) {
                $insertStudent->execute([$name, $registration, $classId, $now]);
            }

            $insertGuardian = $pdo->prepare(
                'INSERT INTO guardians (name, email, relationship, created_at) VALUES (?, ?, ?, ?)'
            );
            $guardians = [
                ['Maria Almeida', 'maria.almeida@example.test', 'Mãe'],
                ['João Souza', 'joao.souza@example.test', 'Pai'],
                ['Ana Costa', 'ana.costa@example.test', 'Mãe'],
            ];
            foreach ($guardians as [$name, $email, $relationship]) {
                $insertGuardian->execute([$name, $email, $relationship, $now]);
            }

            $link = $pdo->prepare('INSERT INTO student_guardians (student_id, guardian_id) VALUES (?, ?)');
            $link->execute([1, 1]);
            $link->execute([2, 2]);
            $link->execute([4, 3]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
