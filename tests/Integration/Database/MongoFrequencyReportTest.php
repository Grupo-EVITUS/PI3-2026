<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Mongo;
use Saa\Database\MongoFrequencyReport;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$db = Mongo::database('saa_frequency_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $classes = [new ObjectId(), new ObjectId()];
    $students = [new ObjectId(), new ObjectId(), new ObjectId()];
    $actor = new ObjectId();
    $now = new UTCDateTime();
    for ($day = 1; $day <= 20; $day++) {
        $class = $day <= 10 ? 0 : 1;
        $entries = [];
        foreach ($students as $index => $student) {
            $entries[] = ['student_id' => $student, 'student_name' => 'Aluno ' . ($index + 1),
                'registration_no' => 'TEST-' . ($index + 1),
                'status' => $day <= [18, 17, 16][$index] ? 'PRESENTE' : 'AUSENTE'];
        }
        $db->attendance_sessions->insertOne(['_id' => new ObjectId(), 'class_id' => $classes[$class],
            'attendance_date' => sprintf('2026-10-%02d', $day), 'completed_by' => $actor,
            'completed_at' => $now, 'class_name' => $class === 0 ? 'Turma A' : 'Turma B',
            'academic_year' => 2026, 'class_shift' => 'Manhã', 'entries' => $entries,
            'roster_hash' => hash('sha256', (string)$day), 'status' => 'CONCLUIDA']);
    }
    $db->attendance_sessions->insertOne(['_id' => new ObjectId(), 'class_id' => $classes[0],
        'attendance_date' => '2026-10-21', 'completed_by' => $actor,
        'completed_at' => $now, 'class_name' => 'Turma A', 'academic_year' => 2026,
        'class_shift' => 'Manhã', 'entries' => [['student_id' => $students[0],
            'student_name' => 'Aluno 1', 'status' => 'AUSENTE']],
        'roster_hash' => hash('sha256', 'processing'), 'status' => 'PROCESSANDO']);
    $db->absences->insertOne(['_id' => new ObjectId(), 'student_id' => $students[0],
        'absence_date' => '2026-10-25', 'status' => 'PENDENTE', 'registered_by' => $actor,
        'created_at' => $now, 'class_snapshot_source' => 'unknown']);

    $reporter = new MongoFrequencyReport($db);
    $report = $reporter->report('2026-10-01', '2026-10-31');
    if ($report['summary']['session_count'] !== 20 || $report['summary']['total'] !== 60
        || $report['summary']['present'] !== 51 || $report['summary']['rate'] !== 85.0
        || $report['summary']['band'] !== 'yellow' || count($report['sessions']) !== 20
        || $report['classes'][0]['rate'] !== 100.0 || $report['classes'][0]['band'] !== 'green'
        || $report['classes'][1]['rate'] !== 70.0 || $report['classes'][1]['band'] !== 'red') {
        throw new RuntimeException('Resumo por chamadas concluídas divergente.');
    }
    $byStudent = [];
    foreach ($report['students'] as $row) { $byStudent[$row['student_id']] = $row; }
    if ($byStudent[(string)$students[0]]['rate'] !== 90.0
        || $byStudent[(string)$students[0]]['band'] !== 'green'
        || $byStudent[(string)$students[1]]['rate'] !== 85.0
        || $byStudent[(string)$students[1]]['band'] !== 'yellow'
        || $byStudent[(string)$students[2]]['rate'] !== 80.0
        || $byStudent[(string)$students[2]]['band'] !== 'red') {
        throw new RuntimeException('Faixas de frequência por aluno divergentes.');
    }
    $filtered = $reporter->report('2026-10-11', '2026-10-20', $classes[1], $students[2]);
    if ($filtered['summary']['session_count'] !== 10 || $filtered['summary']['total'] !== 10
        || $filtered['summary']['present'] !== 6 || count($filtered['students']) !== 1
        || $filtered['students'][0]['student_id'] !== (string)$students[2]) {
        throw new RuntimeException('Filtro por período/turma/aluno ou transferência divergente.');
    }
    $empty = $reporter->report('2026-09-01', '2026-09-30');
    if ($empty['summary']['total'] !== 0 || $empty['summary']['rate'] !== null
        || $empty['summary']['band'] !== 'no_data' || $empty['sessions'] !== []) {
        throw new RuntimeException('Sem chamadas não pode haver taxa.');
    }
    // A apresentação truncada nunca cruza a faixa calculada com a fração exata.
    $threshold = new ReflectionMethod(MongoFrequencyReport::class, 'withRate');
    $nearNinety = $threshold->invoke($reporter, ['present' => 89995, 'absent' => 10005, 'total' => 100000]);
    if ($nearNinety['rate'] !== 89.9 || $nearNinety['band'] !== 'yellow') {
        throw new RuntimeException('Faixa não pode depender do percentual arredondado.');
    }
    $nearEightyFive = $threshold->invoke($reporter, ['present' => 84995, 'absent' => 15005, 'total' => 100000]);
    if ($nearEightyFive['rate'] !== 84.9 || $nearEightyFive['band'] !== 'red') {
        throw new RuntimeException('Faixa vermelha não pode cruzar 85% após exibição.');
    }
    foreach ([['2026-02-30', '2026-03-01'], ['2026-11-02', '2026-11-01']] as [$from, $to]) {
        try {
            $reporter->report($from, $to);
            throw new LogicException('Data inválida aceita.');
        } catch (InvalidArgumentException) {
            // Esperado.
        }
    }
    fwrite(STDOUT, "MongoFrequencyReportTest: períodos, faixas, transferência e ausência de dados passaram.\n");
} finally {
    $db->drop();
}
