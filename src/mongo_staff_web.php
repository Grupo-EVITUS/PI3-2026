<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\Mongo;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\Bson;
use Saa\Database\MongoUserAuthenticator;

function renderSchoolDirectory(): void
{
    $database = Mongo::database();
    $classes = $database->classes->find(['active' => true], ['sort' => ['name' => 1]])->toArray();
    $students = $database->students->find(['active' => true], ['sort' => ['name' => 1]])->toArray();
    $guardians = $database->guardians->find(['active' => true], ['sort' => ['name' => 1]])->toArray();
    $links = [];
    foreach ($database->student_guardians->find() as $link) {
        $links[(string)$link['guardian_id']][] = (string)$link['student_id'];
    }
    render('staff_registration.php', compact('classes', 'students', 'guardians', 'links'));
}

if ($path === '/' || $path === '/index.php') {
    Auth::redirect(empty($_SESSION['user_id']) ? '/login' : '/cadastros');
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
            Auth::redirect('/cadastros');
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
if (!in_array($path, ['/cadastros', '/cadastros/usuario', '/cadastros/turma', '/cadastros/aluno', '/cadastros/responsavel', '/cadastros/arquivar'], true)) {
    notFound();
}
Auth::requireRole(['COORDENACAO']);
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
