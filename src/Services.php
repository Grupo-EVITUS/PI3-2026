<?php
declare(strict_types=1);

final class Services
{
    public const REASONS = [
        'DOENCA' => 'Doença',
        'CONSULTA_MEDICA' => 'Consulta médica',
        'PROBLEMA_FAMILIAR' => 'Problema familiar',
        'TRANSPORTE' => 'Problema de transporte',
        'OUTRO' => 'Outro',
    ];

    public static function recordAttendance(PDO $pdo, int $classId, array $studentStatuses, string $absenceDate, int $userId): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $absenceDate);
        if (!$date || $date->format('Y-m-d') !== $absenceDate) {
            throw new InvalidArgumentException('Informe uma data válida.');
        }
        if ($classId < 1 || $userId < 1 || $studentStatuses === []) {
            throw new InvalidArgumentException('Selecione uma turma com alunos e informe a chamada completa.');
        }

        $pdo->beginTransaction();
        try {
            $studentsQuery = $pdo->prepare(
                'SELECT id, name FROM students WHERE active = 1 AND class_id = ? ORDER BY name'
            );
            $studentsQuery->execute([$classId]);
            $students = $studentsQuery->fetchAll();
            $normalizedStatuses = [];
            foreach ($studentStatuses as $rawId => $status) {
                $id = filter_var((string)$rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || !in_array($status, ['PRESENTE', 'AUSENTE'], true)) {
                    throw new InvalidArgumentException('A chamada contém aluno ou situação inválida.');
                }
                $normalizedStatuses[(int)$id] = $status;
            }
            $rosterIds = array_map(static fn(array $student): int => (int)$student['id'], $students);
            $submittedIds = array_keys($normalizedStatuses);
            sort($rosterIds);
            sort($submittedIds);
            if ($rosterIds === [] || $rosterIds !== $submittedIds) {
                throw new InvalidArgumentException('Informe a situação de todos os alunos ativos da turma selecionada.');
            }
            $classQuery = $pdo->prepare('SELECT name FROM classes WHERE id = ? AND active = 1');
            $classQuery->execute([$classId]);
            $className = $classQuery->fetchColumn();
            if (!$className) {
                throw new InvalidArgumentException('A turma selecionada não está disponível.');
            }

            $existingSession = $pdo->prepare('SELECT 1 FROM attendance_sessions WHERE class_id = ? AND attendance_date = ?');
            $existingSession->execute([$classId, $absenceDate]);
            if ($existingSession->fetchColumn()) {
                throw new DomainException('Já existe uma chamada para esta turma e data. Nenhuma alteração foi realizada.');
            }

            $absentIds = array_keys(array_filter($normalizedStatuses, static fn(string $status): bool => $status === 'AUSENTE'));
            if ($absentIds !== []) {
                $placeholders = implode(',', array_fill(0, count($absentIds), '?'));
                $duplicateQuery = $pdo->prepare(
                    "SELECT s.name FROM absences a JOIN students s ON s.id = a.student_id "
                    . "WHERE a.absence_date = ? AND a.student_id IN ($placeholders)"
                );
                $duplicateQuery->execute([$absenceDate, ...$absentIds]);
                $duplicateNames = $duplicateQuery->fetchAll(PDO::FETCH_COLUMN);
                if ($duplicateNames !== []) {
                    throw new DomainException(
                        'Já existe ausência nesta data para: ' . implode(', ', $duplicateNames)
                        . '. Nenhuma chamada foi registrada.'
                    );
                }
            }

            $absenceRows = [];
            $withoutGuardians = [];
            $notifications = [];
            $now = Database::now();
            $insertSession = $pdo->prepare(
                'INSERT INTO attendance_sessions (class_id, attendance_date, completed_by, completed_at) VALUES (?, ?, ?, ?)'
            );
            $insertSession->execute([$classId, $absenceDate, $userId, $now]);
            $sessionId = (int)$pdo->lastInsertId();
            audit(
                $pdo,
                $userId,
                'CHAMADA_CONCLUIDA',
                'chamada',
                $sessionId,
                'Turma ' . $classId . '; data ' . $absenceDate
            );
            $insertAttendance = $pdo->prepare(
                'INSERT INTO attendance_records (session_id, student_id, status, created_at) VALUES (?, ?, ?, ?)'
            );
            foreach ($students as $student) {
                $studentId = (int)$student['id'];
                $status = $normalizedStatuses[$studentId];
                $insertAttendance->execute([$sessionId, $studentId, $status, $now]);
                if ($status !== 'AUSENTE') {
                    continue;
                }
                $insertAbsence = $pdo->prepare(
                    "INSERT INTO absences (student_id, class_id, attendance_session_id, absence_date, status, registered_by, created_at) "
                    . "VALUES (?, ?, ?, ?, 'PENDENTE', ?, ?)"
                );
                $insertAbsence->execute([$studentId, $classId, $sessionId, $absenceDate, $userId, $now]);
                $absenceId = (int)$pdo->lastInsertId();
                $absenceRows[] = ['id' => $absenceId, 'student_name' => $student['name']];
                audit($pdo, $userId, 'AUSENCIA_REGISTRADA', 'ausencia', $absenceId, 'Data ' . $absenceDate);

                $guardianQuery = $pdo->prepare(
                    'SELECT g.id, g.name, g.email FROM guardians g '
                    . 'JOIN student_guardians sg ON sg.guardian_id = g.id '
                    . 'WHERE sg.student_id = ? AND g.active = 1 ORDER BY g.id'
                );
                $guardianQuery->execute([$studentId]);
                $guardians = $guardianQuery->fetchAll();
                if ($guardians === []) {
                    $withoutGuardians[] = $student['name'];
                }

                foreach ($guardians as $guardian) {
                    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $tokenHash = hash('sha256', $token);
                    $insertCommunication = $pdo->prepare(
                        "INSERT INTO communications "
                        . "(absence_id, guardian_id, email_to, token_hash, status, created_at) "
                        . "VALUES (?, ?, ?, ?, 'PENDENTE', ?)"
                    );
                    $insertCommunication->execute([
                        $absenceId,
                        (int)$guardian['id'],
                        $guardian['email'],
                        $tokenHash,
                        Database::now(),
                    ]);
                    $communicationId = (int)$pdo->lastInsertId();
                    $url = baseUrl('/justificar/' . rawurlencode($token));
                    $subject = 'Comunicado de ausência — ' . $student['name'];
                    $body = "Olá, " . $guardian['name'] . ".\n\n"
                        . "Informamos que " . $student['name'] . " não compareceu à turma "
                        . $className . " em " . date('d/m/Y', strtotime($absenceDate)) . ".\n\n"
                        . "Para enviar a justificativa, acesse o link individual:\n" . $url
                        . "\n\nAtenciosamente,\nEquipe escolar";
                    self::queueEmail($pdo, $communicationId, $guardian['email'], $subject, $body);
                    $notifications[] = [
                        'absence_id' => $absenceId,
                        'student_name' => $student['name'],
                        'guardian_name' => $guardian['name'],
                        'recipient' => $guardian['email'],
                        'communication_id' => $communicationId,
                    ];
                }
            }
            $pdo->commit();
            return [
                'attendance_session_id' => $sessionId,
                'absences' => $absenceRows,
                'attendance_counts' => [
                    'present' => count($students) - count($absentIds),
                    'absent' => count($absentIds),
                    'total' => count($students),
                ],
                'without_guardians' => $withoutGuardians,
                'notifications' => $notifications,
            ];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof PDOException) {
                $databaseMessage = strtolower($error->getMessage());
                if (str_contains($databaseMessage, 'attendance_sessions.class_id')) {
                    throw new DomainException('Já existe uma chamada para esta turma e data. Nenhuma alteração foi realizada.');
                }
                if (str_contains($databaseMessage, 'absences.student_id')) {
                    throw new DomainException('Já existe ausência nesta data para um ou mais alunos. Nenhuma chamada foi registrada.');
                }
            }
            throw $error;
        }
    }

    public static function retryCommunication(PDO $pdo, int $communicationId, int $userId): int
    {
        $query = $pdo->prepare(
            'SELECT c.*, g.name AS guardian_name, g.email AS guardian_email, '
            . 'a.absence_date, s.name AS student_name, cl.name AS class_name '
            . 'FROM communications c JOIN guardians g ON g.id = c.guardian_id '
            . 'JOIN absences a ON a.id = c.absence_id '
            . 'JOIN students s ON s.id = a.student_id JOIN classes cl ON cl.id = a.class_id '
            . 'WHERE c.id = ?'
        );
        $query->execute([$communicationId]);
        $old = $query->fetch();
        if (!$old) {
            throw new InvalidArgumentException('Comunicação não encontrada.');
        }
        if ($old['status'] !== 'FALHA') {
            throw new InvalidArgumentException('Só é possível reenviar uma comunicação que falhou.');
        }

        $pdo->beginTransaction();
        try {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $insert = $pdo->prepare(
                "INSERT INTO communications "
                . "(absence_id, guardian_id, email_to, token_hash, status, retry_of_id, created_at) "
                . "VALUES (?, ?, ?, ?, 'PENDENTE', ?, ?)"
            );
            $insert->execute([
                (int)$old['absence_id'],
                (int)$old['guardian_id'],
                $old['guardian_email'],
                hash('sha256', $token),
                (int)$old['id'],
                Database::now(),
            ]);
            $newId = (int)$pdo->lastInsertId();
            $url = baseUrl('/justificar/' . rawurlencode($token));
            $subject = 'Comunicado de ausência — ' . $old['student_name'];
            $body = "Olá, " . $old['guardian_name'] . ".\n\n"
                . "Informamos que " . $old['student_name'] . " não compareceu à turma "
                . $old['class_name'] . " em " . date('d/m/Y', strtotime($old['absence_date'])) . ".\n\n"
                . "Para enviar a justificativa, acesse o link individual:\n" . $url
                . "\n\nAtenciosamente,\nEquipe escolar";
            self::queueEmail($pdo, $newId, $old['guardian_email'], $subject, $body);
            audit($pdo, $userId, 'EMAIL_REENVIO_SOLICITADO', 'comunicacao', $newId, $old['guardian_email']);
            $pdo->commit();
            return (int)$old['absence_id'];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function publicJustification(PDO $pdo, string $token): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{40,50}$/', $token)) {
            return null;
        }
        $query = $pdo->prepare(
            'SELECT c.id AS communication_id, c.expires_at, a.id AS absence_id, '
            . 'a.absence_date, a.status AS absence_status, s.name AS student_name, '
            . 'cl.name AS class_name, g.name AS guardian_name, j.reason, j.explanation, j.submitted_at '
            . 'FROM communications c JOIN absences a ON a.id = c.absence_id '
            . 'JOIN students s ON s.id = a.student_id JOIN classes cl ON cl.id = a.class_id '
            . 'JOIN guardians g ON g.id = c.guardian_id '
            . 'LEFT JOIN justifications j ON j.absence_id = a.id '
            . 'WHERE c.token_hash = ?'
        );
        $query->execute([hash('sha256', $token)]);
        $absence = $query->fetch();
        if (!$absence || ($absence['expires_at'] && strtotime($absence['expires_at']) < time())) {
            return null;
        }
        return $absence;
    }

    public static function submitJustification(PDO $pdo, string $token, string $reason, string $explanation): void
    {
        if (!array_key_exists($reason, self::REASONS)) {
            throw new InvalidArgumentException('Selecione um motivo válido.');
        }
        $explanation = trim($explanation);
        if ($reason === 'OUTRO' && $explanation === '') {
            throw new InvalidArgumentException('Explique o motivo selecionado como Outro.');
        }
        $absence = self::publicJustification($pdo, $token);
        if (!$absence) {
            throw new InvalidArgumentException('Este link é inválido ou não está mais disponível.');
        }
        if ($absence['absence_status'] === 'RESPONDIDA') {
            throw new DomainException('Esta ausência já foi respondida.');
        }

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO justifications '
                . '(absence_id, communication_id, guardian_id, reason, explanation, submitted_at) '
                . 'VALUES (?, ?, (SELECT guardian_id FROM communications WHERE id = ?), ?, ?, ?)'
            );
            $insert->execute([
                (int)$absence['absence_id'],
                (int)$absence['communication_id'],
                (int)$absence['communication_id'],
                $reason,
                $reason === 'OUTRO' ? $explanation : '',
                Database::now(),
            ]);
            $update = $pdo->prepare("UPDATE absences SET status = 'RESPONDIDA' WHERE id = ? AND status = 'PENDENTE'");
            $update->execute([(int)$absence['absence_id']]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('Esta ausência já foi respondida.');
            }
            audit($pdo, null, 'JUSTIFICATIVA_ENVIADA', 'ausencia', (int)$absence['absence_id'], 'Motivo ' . $reason);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'unique')) {
                throw new DomainException('Esta ausência já foi respondida.');
            }
            throw $error;
        }
    }

    public static function saveClass(PDO $pdo, array $input): int
    {
        $name = trim($input['name'] ?? '');
        $grade = trim($input['grade'] ?? '');
        $shift = trim($input['shift'] ?? '');
        $year = filter_var($input['academic_year'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || $grade === '' || !$year || !in_array($shift, ['Manhã', 'Tarde', 'Noite', 'Integral'], true)) {
            throw new InvalidArgumentException('Preencha nome, série, ano letivo e turno da turma.');
        }
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $statement = $pdo->prepare('UPDATE classes SET name = ?, grade = ?, academic_year = ?, shift = ? WHERE id = ?');
            $statement->execute([$name, $grade, $year, $shift, $id]);
            if ($statement->rowCount() === 0 && !self::exists($pdo, 'classes', $id)) {
                throw new InvalidArgumentException('Turma não encontrada.');
            }
            return (int)$id;
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO classes (name, grade, academic_year, shift, created_at) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$name, $grade, $year, $shift, Database::now()]);
            return (int)$pdo->lastInsertId();
        }
    }

    public static function saveStudent(PDO $pdo, array $input): int
    {
        $name = trim($input['name'] ?? '');
        $registration = trim($input['registration_no'] ?? '') ?: null;
        $classId = filter_var($input['class_id'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || !$classId || !self::exists($pdo, 'classes', $classId)) {
            throw new InvalidArgumentException('Informe nome do aluno e uma turma válida.');
        }
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $statement = $pdo->prepare('UPDATE students SET name = ?, registration_no = ?, class_id = ? WHERE id = ?');
            $statement->execute([$name, $registration, $classId, $id]);
            if ($statement->rowCount() === 0 && !self::exists($pdo, 'students', $id)) {
                throw new InvalidArgumentException('Aluno não encontrado.');
            }
            return (int)$id;
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO students (name, registration_no, class_id, created_at) VALUES (?, ?, ?, ?)'
            );
            $statement->execute([$name, $registration, $classId, Database::now()]);
            return (int)$pdo->lastInsertId();
        }
    }

    public static function saveGuardian(PDO $pdo, array $input): int
    {
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $relationship = trim($input['relationship'] ?? '');
        $submittedStudentIds = $input['student_ids'] ?? [];
        if (!is_array($submittedStudentIds)) {
            throw new InvalidArgumentException('A seleção de alunos é inválida.');
        }
        $studentIds = self::normalizeIds($submittedStudentIds);
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $relationship === '') {
            throw new InvalidArgumentException('Informe nome, e-mail válido e parentesco do responsável.');
        }
        if ($studentIds !== []) {
            $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
            $query = $pdo->prepare("SELECT COUNT(*) FROM students WHERE active = 1 AND id IN ($placeholders)");
            $query->execute($studentIds);
            if ((int)$query->fetchColumn() !== count($studentIds)) {
                throw new InvalidArgumentException('Um ou mais alunos selecionados não estão disponíveis.');
            }
        }

        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        $pdo->beginTransaction();
        try {
            if ($id) {
                $update = $pdo->prepare('UPDATE guardians SET name = ?, email = ?, relationship = ? WHERE id = ?');
                $update->execute([$name, $email, $relationship, $id]);
                if ($update->rowCount() === 0 && !self::exists($pdo, 'guardians', $id)) {
                    throw new InvalidArgumentException('Responsável não encontrado.');
                }
                $guardianId = $id;
                $pdo->prepare('DELETE FROM student_guardians WHERE guardian_id = ?')->execute([$guardianId]);
            } else {
                $insert = $pdo->prepare(
                    'INSERT INTO guardians (name, email, relationship, created_at) VALUES (?, ?, ?, ?)'
                );
                $insert->execute([$name, $email, $relationship, Database::now()]);
                $guardianId = (int)$pdo->lastInsertId();
            }
            $link = $pdo->prepare('INSERT INTO student_guardians (student_id, guardian_id) VALUES (?, ?)');
            foreach ($studentIds as $studentId) {
                $link->execute([$studentId, $guardianId]);
            }
            $pdo->commit();
            return (int)$guardianId;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public static function createStaffUser(PDO $pdo, array $input): int
    {
        $name = trim($input['name'] ?? '');
        $email = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $profile = $input['profile'] ?? '';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe nome e e-mail válido.');
        }
        if (!in_array($profile, ['AOE', 'COORDENACAO', 'DIRETOR'], true)) {
            throw new InvalidArgumentException('Selecione um perfil válido.');
        }
        if (strlen($password) < 10) {
            throw new InvalidArgumentException('A senha deve ter pelo menos 10 caracteres.');
        }
        $statement = $pdo->prepare(
            'INSERT INTO users (name, email, password_hash, profile, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $profile, Database::now()]);
        return (int)$pdo->lastInsertId();
    }

    public static function archiveRecord(PDO $pdo, string $table, int $id): void
    {
        if (!in_array($table, ['classes', 'students', 'guardians'], true)) {
            throw new InvalidArgumentException('Tipo de cadastro inválido.');
        }
        if ($table === 'classes') {
            $count = $pdo->prepare('SELECT COUNT(*) FROM students WHERE class_id = ? AND active = 1');
            $count->execute([$id]);
            if ((int)$count->fetchColumn() > 0) {
                throw new DomainException('Transfira ou arquive os alunos ativos antes de arquivar esta turma.');
            }
        }
        $statement = $pdo->prepare("UPDATE $table SET active = 0 WHERE id = ? AND active = 1");
        $statement->execute([$id]);
        if ($statement->rowCount() !== 1) {
            throw new InvalidArgumentException('Cadastro não encontrado ou já arquivado.');
        }
    }

    private static function queueEmail(PDO $pdo, int $communicationId, string $recipient, string $subject, string $body): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO mail_queue (communication_id, recipient, subject, body, status, created_at) "
            . "VALUES (?, ?, ?, ?, 'AGUARDANDO', ?)"
        );
        $statement->execute([$communicationId, $recipient, $subject, $body, Database::now()]);
    }

    private static function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (!is_scalar($value) || !ctype_digit((string)$value) || (int)$value < 1) {
                throw new InvalidArgumentException('A seleção de alunos contém um identificador inválido.');
            }
            $ids[] = (int)$value;
        }
        return array_values(array_unique($ids));
    }

    private static function exists(PDO $pdo, string $table, int $id): bool
    {
        if (!in_array($table, ['classes', 'students', 'guardians'], true)) {
            throw new LogicException('Tabela inválida.');
        }
        $statement = $pdo->prepare("SELECT 1 FROM $table WHERE id = ? AND active = 1");
        $statement->execute([$id]);
        return (bool)$statement->fetchColumn();
    }
}
