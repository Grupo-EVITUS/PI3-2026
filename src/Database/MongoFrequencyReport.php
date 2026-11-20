<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use RuntimeException;

/** Frequência derivada apenas do roster preservado em chamadas concluídas. */
final class MongoFrequencyReport
{
    public function __construct(private readonly Database $database)
    {
    }

    /** @return array{sessions: array, classes: array, students: array, summary: array} */
    public function report(string $from, string $to, ?ObjectId $classId = null, ?ObjectId $studentId = null): array
    {
        foreach ([$from, $to] as $day) {
            try {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            } catch (\ValueError) {
                $date = false;
            }
            if ($date === false || $date->format('Y-m-d') !== $day) {
                throw new InvalidArgumentException('Período inválido.');
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('Data inicial posterior à final.');
        }

        $filter = ['status' => 'CONCLUIDA', 'attendance_date' => ['$gte' => $from, '$lte' => $to]];
        if ($classId !== null) {
            $filter['class_id'] = $classId;
        }
        if ($studentId !== null) {
            $filter['entries.student_id'] = $studentId;
        }
        $sessions = [];
        $classes = [];
        $students = [];
        $summary = $this->emptyCounts();
        $sessionCount = 0;
        $inspected = 0;
        $entriesInspected = 0;
        foreach ($this->database->attendance_sessions->find($filter,
            ['sort' => ['attendance_date' => 1, '_id' => 1], 'limit' => 2001]) as $session) {
            $entriesInspected += count($session['entries']);
            if (++$inspected > 2000 || count($session['entries']) > 1000 || $entriesInspected > 100000) {
                throw new InvalidArgumentException('Período muito amplo para o relatório.');
            }
            $classKey = (string)$session['class_id'];
            $sessionCounts = $this->emptyCounts();
            foreach ($session['entries'] as $entry) {
                $studentKey = (string)$entry['student_id'];
                if ($studentId !== null && $studentKey !== (string)$studentId) {
                    continue;
                }
                $status = $entry['status'];
                if (!in_array($status, ['PRESENTE', 'AUSENTE'], true)) {
                    throw new RuntimeException('Chamada concluída contém situação inválida.');
                }
                $students[$studentKey] ??= ['student_id' => $studentKey, 'name' => $entry['student_name'],
                    'student_name' => $entry['student_name'], 'registration_no' => $entry['registration_no'] ?? null]
                    + $this->emptyCounts();
                $students[$studentKey]['student_name'] = $entry['student_name'];
                $students[$studentKey]['name'] = $entry['student_name'];
                $students[$studentKey]['registration_no'] = $entry['registration_no'] ?? null;
                $this->add($students[$studentKey], $status);
                $this->add($sessionCounts, $status);
            }
            if ($sessionCounts['total'] === 0) {
                continue;
            }
            $sessionCount++;
            $classes[$classKey] ??= ['class_id' => $classKey, 'name' => $session['class_name'],
                'class_name' => $session['class_name']]
                + $this->emptyCounts();
            $classes[$classKey]['name'] = $session['class_name'];
            $classes[$classKey]['class_name'] = $session['class_name'];
            $this->combine($classes[$classKey], $sessionCounts);
            $this->combine($summary, $sessionCounts);
            $sessions[] = ['session_id' => (string)$session['_id'], 'class_id' => $classKey,
                'class_name' => $session['class_name'], 'attendance_date' => $session['attendance_date']]
                + $this->withRate($sessionCounts);
        }
        foreach ($classes as &$class) { $class = $this->withRate($class); }
        unset($class);
        foreach ($students as &$student) { $student = $this->withRate($student); }
        unset($student);
        return ['sessions' => $sessions, 'classes' => array_values($classes),
            'students' => array_values($students),
            'summary' => ['session_count' => $sessionCount] + $this->withRate($summary)];
    }

    private function emptyCounts(): array
    {
        return ['present' => 0, 'absent' => 0, 'total' => 0];
    }

    private function add(array &$counts, string $status): void
    {
        $counts[$status === 'PRESENTE' ? 'present' : 'absent']++;
        $counts['total']++;
    }

    private function combine(array &$counts, array $other): void
    {
        foreach (['present', 'absent', 'total'] as $key) {
            $counts[$key] += $other[$key];
        }
    }

    private function withRate(array $counts): array
    {
        // Truncar a apresentação impede que 89,995% apareça como 90,0% verde.
        $rate = $counts['total'] > 0 ? floor(1000 * $counts['present'] / $counts['total']) / 10 : null;
        $band = $counts['total'] === 0 ? 'no_data'
            : ($counts['present'] * 100 >= $counts['total'] * 90 ? 'green'
                : ($counts['present'] * 100 >= $counts['total'] * 85 ? 'yellow' : 'red'));
        return $counts + ['rate' => $rate,
            'band' => $band];
    }
}
