<?php
declare(strict_types=1);

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa-test-' . bin2hex(random_bytes(6)) . '.sqlite3';
putenv('DATABASE_PATH=' . $databasePath);
putenv('SEED_DEMO=false');
putenv('MAIL_MODE=log');

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/MailQueue.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Teste falhou: ' . $message);
    }
}

function expectException(callable $work, string $exceptionClass, string $message): void
{
    try {
        $work();
    } catch (Throwable $error) {
        check($error instanceof $exceptionClass, $message . ' (exceção: ' . $error::class . ')');
        return;
    }
    throw new RuntimeException('Teste falhou: esperado ' . $exceptionClass . ' — ' . $message);
}

$now = Database::now();
$pdo->prepare(
    'INSERT INTO users (name, email, password_hash, profile, created_at) VALUES (?, ?, ?, ?, ?)'
)->execute(['AOE Teste', 'aoe@test.local', password_hash('SenhaSegura123!', PASSWORD_DEFAULT), 'AOE', $now]);
$userId = (int)$pdo->lastInsertId();
$pdo->prepare(
    'INSERT INTO classes (name, grade, academic_year, shift, created_at) VALUES (?, ?, ?, ?, ?)'
)->execute(['Turma Teste', '5º ano', 2026, 'Manhã', $now]);
$classId = (int)$pdo->lastInsertId();
$studentInsert = $pdo->prepare(
    'INSERT INTO students (name, registration_no, class_id, created_at) VALUES (?, ?, ?, ?)'
);
$studentInsert->execute(['Aluno Um', 'T-001', $classId, $now]);
$studentOne = (int)$pdo->lastInsertId();
$studentInsert->execute(['Aluno Dois', 'T-002', $classId, $now]);
$studentTwo = (int)$pdo->lastInsertId();
$studentInsert->execute(['Aluno Três', 'T-003', $classId, $now]);
$studentThree = (int)$pdo->lastInsertId();
$guardianInsert = $pdo->prepare(
    'INSERT INTO guardians (name, email, relationship, created_at) VALUES (?, ?, ?, ?)'
);
$guardianInsert->execute(['Responsável Um', 'um@example.test', 'Mãe', $now]);
$guardianOne = (int)$pdo->lastInsertId();
$guardianInsert->execute(['Responsável Dois', 'dois@example.test', 'Pai', $now]);
$guardianTwo = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO student_guardians (student_id, guardian_id) VALUES (?, ?)')->execute([$studentOne, $guardianOne]);
$pdo->prepare('INSERT INTO student_guardians (student_id, guardian_id) VALUES (?, ?)')->execute([$studentOne, $guardianTwo]);

$date = date('Y-m-d');
$result = Services::recordAbsences($pdo, $classId, [$studentOne, $studentTwo], $date, $userId);
check(count($result['absences']) === 2, 'registra vários alunos na mesma operação');
check(count($result['notifications']) === 2, 'notifica todos os responsáveis do aluno');
check($result['without_guardians'] === ['Aluno Dois'], 'sinaliza aluno sem responsável cadastrado');
check((int)$pdo->query("SELECT COUNT(*) FROM mail_queue WHERE status = 'AGUARDANDO'")->fetchColumn() === 2, 'deixa e-mails na fila sem bloquear o registro');

$queuedMessages = $pdo->query('SELECT communication_id, body FROM mail_queue ORDER BY id')->fetchAll();
preg_match('#/justificar/([A-Za-z0-9_-]+)#', $queuedMessages[0]['body'], $tokenMatch);
$token = $tokenMatch[1] ?? '';
check($token !== '', 'gera link aleatório para o formulário público');
$storedHash = $pdo->prepare('SELECT token_hash FROM communications WHERE id = ?');
$storedHash->execute([$queuedMessages[0]['communication_id']]);
check(hash('sha256', $token) === $storedHash->fetchColumn(), 'armazena hash do token, não o token em claro na comunicação');
$absence = Services::publicJustification($pdo, $token);
check($absence !== null && $absence['student_name'] === 'Aluno Um', 'link válido revela somente a ocorrência correspondente');
check(Services::publicJustification($pdo, str_repeat('x', 43)) === null, 'token inválido não revela ocorrência');
expectException(
    static fn() => Services::submitJustification($pdo, $token, 'OUTRO', ''),
    InvalidArgumentException::class,
    'Outro exige texto explicativo'
);
Services::submitJustification($pdo, $token, 'DOENCA', '');
$answered = Services::publicJustification($pdo, $token);
check($answered['absence_status'] === 'RESPONDIDA', 'justificativa atualiza o status da ocorrência');
check($answered['reason'] === 'DOENCA', 'armazena motivo da justificativa');
$secondToken = '';
preg_match('#/justificar/([A-Za-z0-9_-]+)#', $queuedMessages[1]['body'], $secondMatch);
$secondToken = $secondMatch[1] ?? '';
check(Services::publicJustification($pdo, $secondToken)['absence_status'] === 'RESPONDIDA', 'primeira justificativa encerra os links dos demais responsáveis');
expectException(
    static fn() => Services::submitJustification($pdo, $secondToken, 'OUTRO', 'Envio duplicado'),
    DomainException::class,
    'não permite segunda justificativa'
);

expectException(
    static fn() => Services::recordAbsences($pdo, $classId, [$studentOne, $studentThree], $date, $userId),
    DomainException::class,
    'bloqueia ausência duplicada no mesmo aluno e data'
);
$checkUnchanged = $pdo->prepare('SELECT COUNT(*) FROM absences WHERE student_id = ? AND absence_date = ?');
$checkUnchanged->execute([$studentThree, $date]);
check((int)$checkUnchanged->fetchColumn() === 0, 'lote duplicado é cancelado sem registro parcial');

$processed = processMailQueue($pdo, 10);
check($processed === 2, 'processa mensagens do worker local');
check((int)$pdo->query("SELECT COUNT(*) FROM communications WHERE status = 'ENVIADO'")->fetchColumn() === 2, 'registra resultado do envio');
check((int)$pdo->query("SELECT COUNT(*) FROM mail_queue WHERE body <> ''")->fetchColumn() === 0, 'remove o corpo com link secreto após processar a mensagem');
check((int)$pdo->query("SELECT COUNT(*) FROM operation_logs WHERE action = 'JUSTIFICATIVA_ENVIADA'")->fetchColumn() === 1, 'audita envio de justificativa');

$newClassId = Services::saveClass($pdo, [
    'name' => 'Turma Nova',
    'grade' => '8º ano',
    'academic_year' => '2026',
    'shift' => 'Tarde',
]);
Services::saveClass($pdo, [
    'id' => (string)$newClassId,
    'name' => 'Turma Atualizada',
    'grade' => '8º ano',
    'academic_year' => '2026',
    'shift' => 'Tarde',
]);
$newStudentId = Services::saveStudent($pdo, [
    'name' => 'Aluno Novo',
    'registration_no' => 'T-004',
    'class_id' => (string)$newClassId,
]);
$guardianId = Services::saveGuardian($pdo, [
    'name' => 'Responsável Novo',
    'email' => 'novo@example.test',
    'relationship' => 'Pai',
    'student_ids' => [(string)$newStudentId, (string)$studentThree],
]);
check((int)$pdo->query('SELECT COUNT(*) FROM student_guardians WHERE guardian_id = ' . $guardianId)->fetchColumn() === 2, 'vincula um responsável a vários alunos');
Services::saveGuardian($pdo, [
    'id' => (string)$guardianId,
    'name' => 'Responsável Atualizado',
    'email' => 'novo@example.test',
    'relationship' => 'Responsável',
    'student_ids' => [(string)$newStudentId],
]);
check((int)$pdo->query('SELECT COUNT(*) FROM student_guardians WHERE guardian_id = ' . $guardianId)->fetchColumn() === 1, 'atualiza os vínculos do responsável');
$newUserId = Services::createStaffUser($pdo, [
    'name' => 'Coordenação Nova',
    'email' => 'coord-nova@example.test',
    'password' => 'SenhaForte123!',
    'profile' => 'COORDENACAO',
]);
$passwordQuery = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
$passwordQuery->execute([$newUserId]);
check(password_verify('SenhaForte123!', $passwordQuery->fetchColumn()), 'cria usuário com hash de senha');

$pdo->prepare('INSERT INTO student_guardians (student_id, guardian_id) VALUES (?, ?)')->execute([$studentThree, $guardianOne]);
$failureDate = date('Y-m-d', strtotime('+1 day'));
$failureBatch = Services::recordAbsences($pdo, $classId, [$studentThree], $failureDate, $userId);
$failedCommunicationId = (int)$failureBatch['notifications'][0]['communication_id'];
putenv('MAIL_MODE=smtp');
putenv('SMTP_HOST=');
check(processMailQueue($pdo, 1) === 1, 'processa a tentativa que falha');
$failedStatus = $pdo->prepare('SELECT status FROM communications WHERE id = ?');
$failedStatus->execute([$failedCommunicationId]);
check($failedStatus->fetchColumn() === 'FALHA', 'registra falha de SMTP sem perder a ausência');
$failureAbsenceId = Services::retryCommunication($pdo, $failedCommunicationId, $userId);
$retry = $pdo->prepare('SELECT id, status, retry_of_id FROM communications WHERE retry_of_id = ?');
$retry->execute([$failedCommunicationId]);
$retryRow = $retry->fetch();
check($retryRow && $retryRow['status'] === 'PENDENTE', 'reenvio cria uma nova comunicação pendente');
check($failureAbsenceId > 0, 'reenviar preserva e referencia a ocorrência original');
putenv('MAIL_MODE=log');
check(processMailQueue($pdo, 1) === 1, 'processa o reenvio');

unset(
    $storedHash,
    $checkUnchanged,
    $studentInsert,
    $guardianInsert,
    $passwordQuery,
    $failedStatus,
    $retry
);
$pdo = null;
gc_collect_cycles();
foreach ([$databasePath, $databasePath . '-journal', $databasePath . '-wal', $databasePath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
echo "Todos os testes passaram.\n";
