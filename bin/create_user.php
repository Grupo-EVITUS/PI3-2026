<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    fwrite(STDERR, "Uso: php bin/create_user.php <AOE|COORDENACAO|DIRETOR> <nome> <email>\n");
    exit(2);
}
$password = getenv('INITIAL_USER_PASSWORD') ?: '';
if ($password === '') {
    fwrite(STDERR, "Defina INITIAL_USER_PASSWORD no ambiente antes de executar.\n");
    exit(2);
}
try {
    $id = Services::createStaffUser($pdo, [
        'profile' => $argv[1],
        'name' => $argv[2],
        'email' => $argv[3],
        'password' => $password,
    ]);
    fwrite(STDOUT, "Usuário criado com id $id.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível criar o usuário: " . $error->getMessage() . "\n");
    exit(1);
}
