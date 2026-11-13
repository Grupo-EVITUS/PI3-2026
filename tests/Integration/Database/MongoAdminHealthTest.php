<?php
declare(strict_types=1);

use Saa\Database\HealthCheck;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$database = Mongo::database('saa_admin_health_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($database);
    $ready = HealthCheck::inspect($database);
    check($ready['status'] === 'ready', 'Banco inicializado deve estar pronto.');

    $password = 'TesteSeguro-2026!';
    $id = InitialAdmin::create($database, 'Coordenação Inicial', 'Coord@example.org', $password);
    $user = $database->users->findOne(['_id' => $id]);
    check(is_array($user), 'Conta inicial deve ser persistida.');
    check(password_verify($password, $user['password_hash']), 'Senha deve ser gravada somente como hash.');
    check($user['email_key'] === 'coord@example.org', 'Chave de e-mail deve seguir a dobra ASCII.');
    check($user['profile'] === 'COORDENACAO' && $user['is_initial_admin'] === true,
        'Conta inicial deve receber perfil de coordenação e marcador de bootstrap.');

    rejected(
        fn() => InitialAdmin::create($database, 'Outra pessoa', 'outro@example.org', 'OutraSenha-Segura123!'),
        'Bootstrap não pode criar um segundo administrador inicial.'
    );
    rejected(
        fn() => InitialAdmin::create($database, 'Nome', 'invalido', 'OutraSenha-Segura123!'),
        'E-mail inválido deve ser recusado.'
    );
    rejected(
        fn() => InitialAdmin::create($database, 'Nome', 'valido@example.org', 'curta'),
        'Senha fraca deve ser recusada.'
    );
    check($database->users->countDocuments() === 1, 'Rejeições não podem criar usuários adicionais.');

    $database->users->dropIndex('users_initial_admin_unique');
    $database->users->createIndex(['email' => 1], ['name' => 'users_initial_admin_unique']);
    $notReady = HealthCheck::inspect($database);
    check($notReady['status'] === 'not_ready', 'Health check deve falhar quando índice obrigatório falta.');
    check(in_array('users.users_initial_admin_unique', $notReady['checks']['indexes']['invalid'], true),
        'Health check deve detectar um índice com nome correto, mas definição incorreta.');

    $database->command(['collMod' => 'users', 'validationLevel' => 'off'])->toArray();
    $validatorDisabled = HealthCheck::inspect($database);
    check($validatorDisabled['status'] === 'not_ready', 'Health check deve detectar validator desativado.');
    check(in_array('users', $validatorDisabled['checks']['validators']['invalid'], true),
        'Health check deve apontar validator incompatível.');

    $database->operation_logs->dropIndex('logs_happened_at');
    $database->operation_logs->createIndex(['happened_at' => -1], [
        'name' => 'logs_happened_at',
        'expireAfterSeconds' => 60,
    ]);
    $ttlIndex = HealthCheck::inspect($database);
    check($ttlIndex['status'] === 'not_ready', 'Health check não pode aceitar TTL em índice de auditoria.');
    check(in_array('operation_logs.logs_happened_at', $ttlIndex['checks']['indexes']['invalid'], true),
        'Health check deve detectar índice TTL que apagaria logs.');

    $database->dropCollection('classes');
    $missingCollection = HealthCheck::inspect($database);
    check($missingCollection['status'] === 'not_ready', 'Health check deve detectar coleção obrigatória ausente.');
    check(in_array('classes', $missingCollection['checks']['collections']['missing'], true),
        'Health check deve informar a coleção ausente.');

    fwrite(STDOUT, "MongoAdminHealthTest: criação segura e health check passaram.\n");
} finally {
    $database->drop();
}
