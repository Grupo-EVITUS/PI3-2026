<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Database\Mongo;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\MongoUserAuthenticator;

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
if (!in_array($path, ['/cadastros', '/cadastros/usuario'], true)) {
    notFound();
}
Auth::requireRole(['COORDENACAO']);
if ($path === '/cadastros' && $method === 'GET') {
    render('staff_registration.php');
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
render('staff_registration.php');
