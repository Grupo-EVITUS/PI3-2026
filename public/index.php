<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/MailQueue.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método não permitido.');
}
if ($method === 'POST') {
    Auth::validateCsrf();
}

function queryRows(PDO $pdo, string $sql, array $parameters = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function notFound(): never
{
    http_response_code(404);
    render('error.php', ['title' => 'Página não encontrada', 'message' => 'O conteúdo solicitado não foi encontrado.']);
    exit;
}

try {
    if ($path === '/' || $path === '/index.php') {
        Auth::redirect(empty($_SESSION['user_id']) ? '/login' : '/ocorrencias');
    }

    if ($path === '/login') {
        if ($method === 'POST') {
            requirePost();
            $email = strtolower(trim($_POST['email'] ?? ''));
            $password = $_POST['password'] ?? '';
            $statement = $pdo->prepare(
                'SELECT id, name, password_hash, profile FROM users WHERE email = ? AND active = 1'
            );
            $statement->execute([$email]);
            $user = $statement->fetch();
            if (!$user || !password_verify($password, $user['password_hash'])) {
                http_response_code(401);
                render('login.php', ['error' => 'E-mail ou senha inválidos.', 'email' => $email, 'next' => $_POST['next'] ?? '']);
            } else {
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['profile'] = $user['profile'];
                Auth::csrfToken();
                $next = $_POST['next'] ?? '';
                Auth::redirect(is_string($next) ? (Auth::safeNext($next) ?? '/ocorrencias') : '/ocorrencias');
            }
        } else {
            render('login.php', ['error' => null, 'email' => '', 'next' => $_GET['next'] ?? '']);
        }
        exit;
    }

    if ($path === '/logout') {
        requirePost();
        $_SESSION = [];
        session_regenerate_id(true);
        Auth::redirect('/login');
    }

    if (preg_match('#^/justificar/([A-Za-z0-9_-]+)$#', $path, $matches)) {
        $token = $matches[1];
        header('Cache-Control: no-store');
        $absence = Services::publicJustification($pdo, $token);
        if (!$absence) {
            notFound();
        }
        if ($method === 'POST') {
            requirePost();
            try {
                Services::submitJustification(
                    $pdo,
                    $token,
                    $_POST['reason'] ?? '',
                    $_POST['explanation'] ?? ''
                );
                render('justification_success.php', ['absence' => $absence]);
            } catch (DomainException $error) {
                $absence = Services::publicJustification($pdo, $token) ?? $absence;
                http_response_code(409);
                render('justification.php', [
                    'absence' => $absence,
                    'error' => $error->getMessage(),
                    'reasons' => Services::REASONS,
                    'selectedReason' => $_POST['reason'] ?? '',
                    'explanation' => $_POST['explanation'] ?? '',
                ]);
            } catch (InvalidArgumentException $error) {
                http_response_code(422);
                render('justification.php', [
                    'absence' => $absence,
                    'error' => $error->getMessage(),
                    'reasons' => Services::REASONS,
                    'selectedReason' => $_POST['reason'] ?? '',
                    'explanation' => $_POST['explanation'] ?? '',
                ]);
            }
        } else {
            render('justification.php', [
                'absence' => $absence,
                'error' => null,
                'reasons' => Services::REASONS,
                'selectedReason' => '',
                'explanation' => '',
            ]);
        }
        exit;
    }

    if ($path === '/ocorrencias') {
        Auth::requireRole(['AOE', 'COORDENACAO', 'DIRETOR']);
        $status = $_GET['status'] ?? '';
        $filtered = in_array($status, ['PENDENTE', 'RESPONDIDA'], true);
        $where = $filtered ? 'WHERE a.status = ?' : '';
        $parameters = $filtered ? [$status] : [];
        $occurrences = queryRows(
            $pdo,
            "SELECT a.id, a.absence_date, a.status, s.name AS student_name, cl.name AS class_name, "
            . "j.reason, j.explanation, j.submitted_at, "
            . "(SELECT COUNT(*) FROM communications c WHERE c.absence_id = a.id) AS communication_count, "
            . "(SELECT COUNT(*) FROM communications c WHERE c.absence_id = a.id AND c.status = 'FALHA') AS failed_count "
            . "FROM absences a JOIN students s ON s.id = a.student_id "
            . "JOIN classes cl ON cl.id = s.class_id LEFT JOIN justifications j ON j.absence_id = a.id "
            . "$where ORDER BY a.absence_date DESC, a.id DESC",
            $parameters
        );
        render('occurrences.php', ['occurrences' => $occurrences, 'activeStatus' => $status]);
        exit;
    }

    if ($path === '/ausencias/registrar') {
        Auth::requireRole(['AOE', 'COORDENACAO']);
        $classes = queryRows($pdo, 'SELECT id, name, grade, shift FROM classes WHERE active = 1 ORDER BY name');
        $selectedClass = (int)($method === 'POST' ? ($_POST['class_id'] ?? 0) : ($_GET['turma_id'] ?? 0));
        $students = $selectedClass > 0
            ? queryRows(
                $pdo,
                'SELECT s.id, s.name, s.registration_no, s.class_id, c.name AS class_name '
                . 'FROM students s JOIN classes c ON c.id = s.class_id '
                . 'WHERE s.active = 1 AND c.active = 1 AND c.id = ? ORDER BY s.name',
                [$selectedClass]
            )
            : [];
        if ($method === 'POST') {
            requirePost();
            try {
                $date = trim($_POST['absence_date'] ?? '') ?: date('Y-m-d');
                $result = Services::recordAbsences(
                    $pdo,
                    (int)($_POST['class_id'] ?? 0),
                    is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : [],
                    $date,
                    (int)$_SESSION['user_id']
                );
                $ids = array_column($result['absences'], 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $communications = queryRows(
                    $pdo,
                    "SELECT c.id, c.absence_id, c.email_to, c.status, g.name AS guardian_name "
                    . "FROM communications c JOIN guardians g ON g.id = c.guardian_id "
                    . "WHERE c.absence_id IN ($placeholders) ORDER BY c.id",
                    $ids
                );
                render('registration_result.php', [
                    'result' => $result,
                    'communications' => $communications,
                    'date' => $date,
                ]);
            } catch (DomainException|InvalidArgumentException $error) {
                http_response_code(422);
                render('register_absence.php', [
                    'classes' => $classes,
                    'students' => $students,
                    'error' => $error->getMessage(),
                    'selectedClass' => $selectedClass,
                    'selectedStudents' => array_map('intval', is_array($_POST['student_ids'] ?? null) ? $_POST['student_ids'] : []),
                    'absenceDate' => $_POST['absence_date'] ?? date('Y-m-d'),
                ]);
            }
        } else {
            render('register_absence.php', [
                'classes' => $classes,
                'students' => $students,
                'error' => null,
                'selectedClass' => $selectedClass,
                'selectedStudents' => [],
                'absenceDate' => date('Y-m-d'),
            ]);
        }
        exit;
    }

    if (preg_match('#^/ausencias/([0-9]+)$#', $path, $matches)) {
        Auth::requireRole(['AOE', 'COORDENACAO', 'DIRETOR']);
        $absenceId = (int)$matches[1];
        $absenceStatement = $pdo->prepare(
            'SELECT a.*, s.name AS student_name, cl.name AS class_name, cl.grade, '
            . 'u.name AS registered_by_name FROM absences a '
            . 'JOIN students s ON s.id = a.student_id JOIN classes cl ON cl.id = s.class_id '
            . 'JOIN users u ON u.id = a.registered_by WHERE a.id = ?'
        );
        $absenceStatement->execute([$absenceId]);
        $absence = $absenceStatement->fetch();
        if (!$absence) {
            notFound();
        }
        $communicationStatement = $pdo->prepare(
            'SELECT c.*, g.name AS guardian_name FROM communications c '
            . 'JOIN guardians g ON g.id = c.guardian_id WHERE c.absence_id = ? ORDER BY c.id'
        );
        $communicationStatement->execute([$absenceId]);
        $communications = $communicationStatement->fetchAll();
        $justificationStatement = $pdo->prepare(
            'SELECT j.*, g.name AS guardian_name FROM justifications j '
            . 'JOIN guardians g ON g.id = j.guardian_id WHERE j.absence_id = ?'
        );
        $justificationStatement->execute([$absenceId]);
        $justification = $justificationStatement->fetch() ?: null;
        render('absence_detail.php', [
            'absence' => $absence,
            'communications' => $communications,
            'justification' => $justification,
            'reasons' => Services::REASONS,
        ]);
        exit;
    }

    if ($path === '/comunicacoes/reenvio') {
        Auth::requireRole(['AOE', 'COORDENACAO']);
        requirePost();
        try {
            $absenceId = Services::retryCommunication(
                $pdo,
                (int)($_POST['communication_id'] ?? 0),
                (int)$_SESSION['user_id']
            );
            flash('Novo envio colocado na fila.');
            Auth::redirect('/ausencias/' . $absenceId);
        } catch (InvalidArgumentException $error) {
            flash($error->getMessage(), 'error');
            Auth::redirect('/ocorrencias');
        }
    }

    if ($path === '/cadastros') {
        Auth::requireRole(['COORDENACAO']);
        $classes = queryRows($pdo, 'SELECT * FROM classes WHERE active = 1 ORDER BY name');
        $students = queryRows(
            $pdo,
            'SELECT s.*, c.name AS class_name FROM students s JOIN classes c ON c.id = s.class_id '
            . 'WHERE s.active = 1 ORDER BY s.name'
        );
        $guardians = queryRows(
            $pdo,
            'SELECT g.*, GROUP_CONCAT(s.name, ", ") AS student_names FROM guardians g '
            . 'LEFT JOIN student_guardians sg ON sg.guardian_id = g.id '
            . 'LEFT JOIN students s ON s.id = sg.student_id AND s.active = 1 '
            . 'WHERE g.active = 1 GROUP BY g.id ORDER BY g.name'
        );
        $staff = queryRows($pdo, 'SELECT id, name, email, profile FROM users WHERE active = 1 ORDER BY name');
        $edit = null;
        $editType = $_GET['tipo'] ?? '';
        $editId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($editId && in_array($editType, ['turma', 'aluno', 'responsavel'], true)) {
            $table = ['turma' => 'classes', 'aluno' => 'students', 'responsavel' => 'guardians'][$editType];
            $query = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND active = 1");
            $query->execute([$editId]);
            $edit = $query->fetch() ?: null;
            if ($editType === 'responsavel' && $edit) {
                $links = $pdo->prepare('SELECT student_id FROM student_guardians WHERE guardian_id = ?');
                $links->execute([$editId]);
                $edit['student_ids'] = array_map('intval', $links->fetchAll(PDO::FETCH_COLUMN));
            }
        }
        render('management.php', [
            'classes' => $classes,
            'students' => $students,
            'guardians' => $guardians,
            'staff' => $staff,
            'edit' => $edit,
            'editType' => $editType,
        ]);
        exit;
    }

    if (in_array($path, ['/cadastros/turma', '/cadastros/aluno', '/cadastros/responsavel', '/cadastros/usuario', '/cadastros/arquivar'], true)) {
        Auth::requireRole(['COORDENACAO']);
        requirePost();
        try {
            if ($path === '/cadastros/turma') {
                $id = Services::saveClass($pdo, $_POST);
                audit($pdo, (int)$_SESSION['user_id'], 'TURMA_SALVA', 'turma', $id);
            } elseif ($path === '/cadastros/aluno') {
                $id = Services::saveStudent($pdo, $_POST);
                audit($pdo, (int)$_SESSION['user_id'], 'ALUNO_SALVO', 'aluno', $id);
            } elseif ($path === '/cadastros/responsavel') {
                $id = Services::saveGuardian($pdo, $_POST);
                audit($pdo, (int)$_SESSION['user_id'], 'RESPONSAVEL_SALVO', 'responsavel', $id);
            } elseif ($path === '/cadastros/usuario') {
                $id = Services::createStaffUser($pdo, $_POST);
                audit($pdo, (int)$_SESSION['user_id'], 'USUARIO_CRIADO', 'usuario', $id);
            } else {
                $table = match ($_POST['tipo'] ?? '') {
                    'turma' => 'classes',
                    'aluno' => 'students',
                    'responsavel' => 'guardians',
                    default => throw new InvalidArgumentException('Tipo de cadastro inválido.'),
                };
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id) {
                    throw new InvalidArgumentException('Cadastro inválido.');
                }
                Services::archiveRecord($pdo, $table, $id);
                audit($pdo, (int)$_SESSION['user_id'], 'CADASTRO_ARQUIVADO', $table, $id);
            }
            flash('Cadastro salvo com sucesso.');
            Auth::redirect('/cadastros');
        } catch (DomainException|InvalidArgumentException $error) {
            flash($error->getMessage(), 'error');
            Auth::redirect('/cadastros');
        } catch (PDOException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique')) {
                flash('Já existe um cadastro com esse e-mail ou número de matrícula.', 'error');
                Auth::redirect('/cadastros');
            }
            throw $error;
        }
    }

    http_response_code(404);
    render('error.php', ['title' => 'Página não encontrada', 'message' => 'O conteúdo solicitado não foi encontrado.']);
} catch (Throwable $error) {
    error_log((string)$error);
    http_response_code(500);
    render('error.php', [
        'title' => 'Erro interno',
        'message' => 'Não foi possível concluir a operação. O erro foi registrado para análise.',
    ]);
}
