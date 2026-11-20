<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\Mongo;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoFrequencyReport;
use Saa\Database\MongoJustification;
use Saa\Database\MongoPushSubscriptions;
use Saa\Push\WebPush;
use Saa\Database\Bson;
use Saa\Database\MongoUserAuthenticator;

function renderMongoAttendance(?string $error = null, array $selectedAttendance = []): void
{
    $database = Mongo::database();
    $actor = Bson::objectId($_SESSION['user_id'] ?? null);
    if ($actor === null || $database->users->countDocuments([
        '_id' => $actor, 'active' => true, 'profile' => ['$in' => ['AOE', 'COORDENACAO']],
    ]) !== 1) {
        http_response_code(403);
        render('error.php', ['title' => 'Acesso negado', 'message' => 'Seu acesso não está ativo.']);
        return;
    }
    $classes = $database->classes->find(['active' => true], ['sort' => ['name' => 1], 'limit' => 200])->toArray();
    $rawClass = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['class_id'] ?? null) : ($_GET['turma_id'] ?? null);
    $classId = Bson::objectId($rawClass);
    $students = [];
    if ($classId !== null) {
        $students = $database->students->find(
            ['class_id' => $classId, 'active' => true],
            ['sort' => ['name' => 1, '_id' => 1], 'limit' => 1001]
        )->toArray();
    } elseif ($rawClass !== null && $rawClass !== '') {
        http_response_code(422);
        $error = 'Turma inválida.';
    }
    $date = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['absence_date'] ?? null) : null;
    $absenceDate = is_string($date) && Bson::isDay($date) ? $date : date('Y-m-d');
    render('mongo_attendance.php', compact('classes', 'classId', 'students', 'error', 'selectedAttendance', 'absenceDate'));
}

function renderSchoolDirectory(): void
{
    $database = Mongo::database();
    $actor = Bson::objectId($_SESSION['user_id'] ?? null);
    if ($actor === null || $database->users->countDocuments(
        ['_id' => $actor, 'profile' => 'COORDENACAO', 'active' => true]
    ) !== 1) {
        http_response_code(403);
        render('error.php', ['title' => 'Acesso negado', 'message' => 'Seu acesso não está ativo.']);
        return;
    }
    $classes = $database->classes->find(['active' => true], ['sort' => ['name' => 1]])->toArray();
    $students = $database->students->find(['active' => true], ['sort' => ['name' => 1]])->toArray();
    $guardians = $database->guardians->find(['active' => true], ['sort' => ['name' => 1]])->toArray();
    $links = [];
    foreach ($database->student_guardians->find() as $link) {
        $links[(string)$link['guardian_id']][] = (string)$link['student_id'];
    }
    $pushDevices = (new MongoPushSubscriptions($database))->activeCounts();
    $pushEnabled = WebPush::configuredPublicKey() !== null;
    render('staff_registration.php', compact('classes', 'students', 'guardians', 'links', 'pushDevices', 'pushEnabled'));
}

/** Dados do painel "ativar notificações" (null quando o servidor não tem chaves VAPID). */
function pushPanel(string $endpoint): ?array
{
    $key = WebPush::configuredPublicKey();
    return $key === null ? null : ['endpoint' => $endpoint, 'vapid_key' => $key];
}

function pushJson(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

/** Inscrição enviada pelo push.js como campo de formulário (o CSRF já foi validado). */
function postedSubscription(): mixed
{
    $raw = $_POST['subscription'] ?? null;
    if (!is_string($raw) || strlen($raw) > 4096) {
        throw new InvalidArgumentException('Inscrição de notificação inválida.');
    }
    try {
        return json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new InvalidArgumentException('Inscrição de notificação inválida.');
    }
}

function subscribeDevice(callable $subscribe): never
{
    if (WebPush::configuredPublicKey() === null) {
        pushJson(503, ['error' => 'As notificações push ainda não foram configuradas pela escola.']);
    }
    try {
        $subscribe(postedSubscription());
    } catch (InvalidArgumentException $error) {
        pushJson(422, ['error' => $error->getMessage()]);
    } catch (DomainException $error) {
        pushJson(409, ['error' => $error->getMessage()]);
    }
    pushJson(201, ['message' => 'Pronto! Os próximos avisos de ausência chegarão como notificação neste aparelho.']);
}

if ($path === '/' || $path === '/index.php') {
    Auth::redirect(empty($_SESSION['user_id']) ? '/login' : staffHomePath());
}
if ($path === '/login') {
    $email = '';
    $error = null;
    if ($method === 'POST') {
        $email = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
        $auth = new MongoUserAuthenticator(Mongo::database()->users);
        $user = $auth->authenticate($email, $_POST['password'] ?? null);
        if ($user !== null) {
            session_regenerate_id(true);
            $_SESSION = ['user_id' => $user['id'], 'user_name' => $user['name'], 'profile' => $user['profile']];
            Auth::csrfToken();
            Auth::redirect(staffHomePath());
        }
        http_response_code(401);
        $error = 'E-mail ou senha inválidos.';
    }
    render('login.php', ['email' => $email, 'error' => $error]);
    return;
}
if ($path === '/logout') {
    requirePost();
    $_SESSION = [];
    session_regenerate_id(true);
    Auth::redirect('/login');
}
if ($path === '/notificacoes') {
    render('push_home.php');
    return;
}
if (preg_match('#^/notificacoes/ativar/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $service = new MongoPushSubscriptions(Mongo::database());
    if ($method === 'POST') {
        subscribeDevice(fn(mixed $subscription) => $service->subscribeWithInvite($matches[1], $subscription));
    }
    $invite = MongoPushSubscriptions::isToken($matches[1]) ? $service->findInvite($matches[1]) : null;
    if ($invite === null) {
        http_response_code(404);
        render('error.php', ['title' => 'Convite indisponível',
            'message' => 'Este convite é inválido, expirou ou já foi usado. Peça um novo convite à escola.']);
        return;
    }
    render('push_activate.php', ['guardianName' => $invite['guardian_name'], 'pushPanel' => pushPanel($path)]);
    return;
}
if (preg_match('#^/justificar/([A-Za-z0-9_-]+)/notificacoes$#', $path, $matches)) {
    requirePost();
    header('Referrer-Policy: no-referrer');
    $service = new MongoPushSubscriptions(Mongo::database());
    subscribeDevice(fn(mixed $subscription) => $service->subscribeWithCommunication($matches[1], $subscription));
}
if (preg_match('#^/justificar/([A-Za-z0-9_-]+)$#', $path, $matches)) {
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $token = $matches[1];
    $service = new MongoJustification(Mongo::database());
    $absence = $service->find($token);
    if ($absence === null) {
        notFound();
    }
    $error = null;
    $selectedReason = '';
    $explanation = '';
    if ($method === 'POST') {
        requirePost();
        try {
            $selectedReason = $_POST['reason'] ?? '';
            $explanation = $_POST['explanation'] ?? '';
            if (!is_string($selectedReason) || !is_string($explanation)) {
                throw new InvalidArgumentException('Justificativa inválida.');
            }
            $service->submit($token, $selectedReason, $explanation);
            render('justification_success.php', ['absence' => $absence]);
            return;
        } catch (DomainException $caught) {
            http_response_code(409);
            $error = $caught->getMessage();
            $absence = $service->find($token) ?? $absence;
        } catch (InvalidArgumentException $caught) {
            http_response_code(422);
            $error = $caught->getMessage();
        }
    }
    render('justification.php', [
        'absence' => $absence, 'error' => $error, 'reasons' => Services::REASONS,
        'pushPanel' => pushPanel('/justificar/' . $token . '/notificacoes'),
        'selectedReason' => is_string($selectedReason) ? $selectedReason : '',
        'explanation' => is_string($explanation) ? $explanation : '',
    ]);
    return;
}
if ($path === '/chamada' && mongoAttendancePreviewEnabled()) {
    Auth::requireRole(['AOE', 'COORDENACAO']);
    if ($method === 'GET') {
        renderMongoAttendance();
        return;
    }
    requirePost();
    try {
        $actor = Bson::objectId($_SESSION['user_id'] ?? null);
        $classId = Bson::objectId($_POST['class_id'] ?? null);
        $date = $_POST['absence_date'] ?? null;
        $attendance = $_POST['attendance'] ?? null;
        if ($actor === null || $classId === null || !is_string($date) || !is_array($attendance)) {
            throw new InvalidArgumentException('Informe turma, data e chamada completa.');
        }
        $result = (new MongoAttendance(Mongo::database()))->record(
            $actor, $classId, $attendance, $date, baseUrl()
        );
        render('mongo_attendance_result.php', ['result' => $result, 'date' => $date]);
    } catch (InvalidArgumentException|DomainException $error) {
        http_response_code(422);
        renderMongoAttendance($error->getMessage(), is_array($_POST['attendance'] ?? null) ? $_POST['attendance'] : []);
    }
    return;
}
if ($path === '/frequencia' && $method === 'GET') {
    Auth::requireRole(['COORDENACAO', 'DIRETOR']);
    header('Cache-Control: no-store');
    $database = Mongo::database();
    $actor = Bson::objectId($_SESSION['user_id'] ?? null);
    if ($actor === null || $database->users->countDocuments([
        '_id' => $actor, 'active' => true, 'profile' => ['$in' => ['COORDENACAO', 'DIRETOR']],
    ]) !== 1) {
        http_response_code(403);
        render('error.php', ['title' => 'Acesso negado', 'message' => 'Seu acesso não está ativo.']);
        return;
    }
    $period = $_GET['periodo'] ?? 'mes';
    $reference = $_GET['referencia'] ?? date('Y-m-d');
    $rawClass = $_GET['turma_id'] ?? '';
    $rawStudent = $_GET['aluno_id'] ?? '';
    if (!is_string($period) || !in_array($period, ['semana', 'mes', 'semestre'], true)
        || !is_string($reference) || !Bson::isDay($reference)
        || !is_string($rawClass) || !is_string($rawStudent)
        || ($rawClass !== '' && Bson::objectId($rawClass) === null)
        || ($rawStudent !== '' && Bson::objectId($rawStudent) === null)) {
        http_response_code(422);
        render('error.php', ['title' => 'Filtros inválidos', 'message' => 'Revise período, data, turma e aluno.']);
        return;
    }
    $day = new DateTimeImmutable($reference . 'T00:00:00Z');
    if ($period === 'semana') {
        $first = $day->modify('monday this week');
        $last = $first->modify('+6 days');
    } elseif ($period === 'mes') {
        $first = $day->modify('first day of this month');
        $last = $day->modify('last day of this month');
    } else {
        $half = (int)$day->format('n') <= 6 ? '01' : '07';
        $first = new DateTimeImmutable($day->format('Y') . '-' . $half . '-01T00:00:00Z');
        $last = $first->modify('+6 months -1 day');
    }
    $classId = $rawClass === '' ? null : Bson::objectId($rawClass);
    $studentId = $rawStudent === '' ? null : Bson::objectId($rawStudent);
    try {
        $report = (new MongoFrequencyReport($database))->report(
            $first->format('Y-m-d'), $last->format('Y-m-d'), $classId, $studentId
        );
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        render('error.php', ['title' => 'Relatório indisponível', 'message' => $error->getMessage()]);
        return;
    }
    $classes = $database->classes->find([], ['sort' => ['name' => 1], 'limit' => 1000])->toArray();
    $students = $database->students->find([], ['sort' => ['name' => 1], 'limit' => 1000])->toArray();
    render('frequency_dashboard.php', compact(
        'report', 'classes', 'students', 'period', 'reference', 'rawClass', 'rawStudent', 'first', 'last'
    ));
    return;
}
if (!in_array($path, ['/cadastros', '/cadastros/usuario', '/cadastros/turma', '/cadastros/aluno', '/cadastros/responsavel', '/cadastros/arquivar', '/cadastros/responsavel/notificacoes'], true)) {
    notFound();
}
Auth::requireRole(['COORDENACAO']);
if ($path === '/cadastros/responsavel/notificacoes') {
    requirePost();
    header('Cache-Control: no-store');
    try {
        $actor = Bson::objectId($_SESSION['user_id'] ?? null);
        $guardianId = Bson::objectId($_POST['id'] ?? null);
        if ($actor === null || $guardianId === null) {
            throw new InvalidArgumentException('Responsável inválido.');
        }
        if (WebPush::configuredPublicKey() === null) {
            throw new InvalidArgumentException('Configure as chaves VAPID antes de convidar responsáveis.');
        }
        $token = (new MongoPushSubscriptions(Mongo::database()))->createInvite($actor, $guardianId);
        $guardian = Mongo::database()->guardians->findOne(['_id' => $guardianId]);
        render('push_invite.php', ['guardianName' => $guardian['name'] ?? '',
            'inviteUrl' => baseUrl('/notificacoes/ativar/' . $token)]);
        return;
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        flash($error->getMessage(), 'error');
    }
    renderSchoolDirectory();
    return;
}
if ($path === '/cadastros' && $method === 'GET') {
    renderSchoolDirectory();
    return;
}
if (in_array($path, ['/cadastros/turma', '/cadastros/aluno', '/cadastros/responsavel', '/cadastros/arquivar'], true)) {
    requirePost();
    try {
        $values = [];
        foreach (['name', 'grade', 'shift', 'registration_no', 'email', 'relationship', 'class_id'] as $field) {
            $values[$field] = $_POST[$field] ?? '';
        }
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new InvalidArgumentException('Dados do cadastro inválidos.');
            }
        }
        $actor = Bson::objectId($_SESSION['user_id']);
        if ($actor === null) {
            throw new RuntimeException('Sessão inválida.');
        }
        $directory = new MongoSchoolDirectory(Mongo::database());
        $target = $_POST['id'] ?? '';
        if (!is_string($target)) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
        $targetId = $target === '' ? null : Bson::objectId($target);
        if ($target !== '' && $targetId === null) {
            throw new InvalidArgumentException('Identificador inválido.');
        }
        if ($path === '/cadastros/arquivar') {
            $entity = $_POST['entity'] ?? '';
            if (!is_string($entity) || $targetId === null) {
                throw new InvalidArgumentException('Cadastro inválido.');
            }
            $directory->archive($actor, $entity, $targetId);
            flash('Cadastro arquivado.');
            Auth::redirect('/cadastros');
        }
        if ($path === '/cadastros/turma') {
            $year = filter_var($_POST['academic_year'] ?? null, FILTER_VALIDATE_INT);
            if (!is_int($year)) {
                throw new InvalidArgumentException('Ano inválido.');
            }
            if ($targetId === null) {
                $directory->createClass($actor, $values['name'], $values['grade'], $year, $values['shift']);
            } else {
                $directory->updateClass($actor, $targetId, $values['name'], $values['grade'], $year, $values['shift']);
            }
        } elseif ($path === '/cadastros/aluno') {
            $classId = Bson::objectId($values['class_id']);
            if ($classId === null) {
                throw new InvalidArgumentException('Turma inválida.');
            }
            if ($targetId === null) {
                $directory->createStudent($actor, $values['name'], $values['registration_no'], $classId);
            } else {
                $directory->updateStudent($actor, $targetId, $values['name'], $values['registration_no'], $classId);
            }
        } else {
            $studentIds = $_POST['student_ids'] ?? [];
            if (!is_array($studentIds)) {
                throw new InvalidArgumentException('Alunos inválidos.');
            }
            $ids = [];
            foreach ($studentIds as $studentId) {
                $id = Bson::objectId($studentId);
                if ($id === null) {
                    throw new InvalidArgumentException('Aluno inválido.');
                }
                $ids[] = $id;
            }
            if ($targetId === null) {
                $directory->createGuardian($actor, $values['name'], $values['email'], $values['relationship'], $ids);
            } else {
                $directory->updateGuardian($actor, $targetId, $values['name'], $values['email'], $values['relationship'], $ids);
            }
        }
        flash('Cadastro escolar salvo.');
        Auth::redirect('/cadastros');
    } catch (InvalidArgumentException $error) {
        http_response_code(422);
        flash($error->getMessage(), 'error');
    } catch (BulkWriteException $error) {
        if ($error->getCode() !== 11000) {
            throw $error;
        }
        http_response_code(409);
        flash('Este cadastro já existe.', 'error');
    }
    renderSchoolDirectory();
    return;
}
if ($path !== '/cadastros/usuario') {
    http_response_code(405);
    header('Allow: GET');
    return;
}
requirePost();
try {
    $fields = [];
    foreach (['name', 'email', 'password', 'profile'] as $key) {
        if (!is_string($_POST[$key] ?? null)) {
            throw new InvalidArgumentException('Preencha todos os campos.');
        }
        $fields[] = $_POST[$key];
    }
    (new MongoStaffRegistration(Mongo::database()))->register(new ObjectId($_SESSION['user_id']), ...$fields);
    flash('Funcionário cadastrado.');
    Auth::redirect('/cadastros');
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    flash($error->getMessage(), 'error');
} catch (BulkWriteException $error) {
    if ($error->getCode() !== 11000) {
        throw $error;
    }
    http_response_code(409);
    flash('Já existe um cadastro com esse e-mail.', 'error');
}
renderSchoolDirectory();
