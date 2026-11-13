<?php
declare(strict_types=1);

use Saa\Database\Mongo;
use Saa\Database\MongoUserAuthenticator;
use Saa\Database\Schema;
use MongoDB\BSON\UTCDateTime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function authCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = Mongo::database('saa_auth_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($database);
    $users = $database->users;
    $authenticator = new MongoUserAuthenticator($users);
    $password = 'Senha de teste-2026!';

    foreach (['AOE', 'COORDENACAO', 'DIRETOR'] as $index => $profile) {
        $email = 'Pessoa' . $index . '@example.org';
        $users->insertOne([
            'name' => 'Pessoa ' . $index,
            'email' => $email,
            'email_key' => Schema::emailKey($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'profile' => $profile,
            'active' => true,
            'created_at' => new UTCDateTime(),
        ]);

        $identity = $authenticator->authenticate(strtoupper($email), $password);
        authCheck(is_array($identity), 'Usuário ativo com senha correta deve autenticar.');
        authCheck($identity['profile'] === $profile, 'O perfil persistido deve ser preservado.');
        authCheck(isset($identity['id'], $identity['name']), 'A identidade mínima deve conter ID e nome.');
        authCheck(preg_match('/^[a-f0-9]{24}$/', $identity['id']) === 1,
            'O ID exposto deve ser um ObjectId hexadecimal serializado.');
        authCheck(!isset($identity['password_hash'], $identity['email_key']), 'A identidade não pode expor campos sensíveis.');
    }

    authCheck($authenticator->authenticate('pessoa0@example.org', 'senha incorreta') === null,
        'Senha incorreta deve falhar sem retornar identidade.');
    authCheck($authenticator->authenticate('ausente@example.org', $password) === null,
        'E-mail inexistente deve falhar sem retornar identidade.');
    authCheck($authenticator->authenticate('endereço inválido', $password) === null,
        'E-mail inválido deve falhar sem exceção.');
    authCheck($authenticator->authenticate(['$ne' => null], $password) === null,
        'Entrada não textual não pode ser usada como filtro MongoDB.');
    authCheck($authenticator->authenticate('pessoa0@example.org', ['password']) === null,
        'Senha não textual deve falhar com segurança.');
    authCheck($authenticator->authenticate('pessoa0@example.org', str_repeat('x', 4097)) === null,
        'Senha acima do limite deve ser recusada.');
    authCheck($authenticator->authenticate(str_repeat('a', 255) . '@example.org', $password) === null,
        'E-mail acima do limite deve ser recusado.');

    foreach ([
        ['email' => 'hash-ausente@example.org', 'profile' => 'AOE'],
        ['email' => 'hash-malformado@example.org', 'profile' => 'AOE', 'password_hash' => 'hash inválido'],
        [
            'email' => 'perfil-invalido@example.org',
            'profile' => 'SUPORTE',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ],
    ] as $invalidUser) {
        $email = $invalidUser['email'];
        $document = [
            'name' => 'Usuário inválido',
            'email' => $email,
            'email_key' => Schema::emailKey($email),
            'profile' => $invalidUser['profile'],
            'active' => true,
            'created_at' => new UTCDateTime(),
        ];
        if (isset($invalidUser['password_hash'])) {
            $document['password_hash'] = $invalidUser['password_hash'];
        }
        $users->insertOne($document, ['bypassDocumentValidation' => true]);
        authCheck($authenticator->authenticate($email, $password) === null,
            'Hash ausente/malformado ou perfil inválido deve falhar fechado.');
    }

    $users->updateOne(['email_key' => 'pessoa0@example.org'], ['$set' => ['active' => false]]);
    authCheck($authenticator->authenticate('Pessoa0@example.org', $password) === null,
        'Usuário inativo não pode autenticar.');

    fwrite(STDOUT, "MongoUserAuthenticatorTest: autenticação Mongo isolada passou.\n");
} finally {
    $database->drop();
}
