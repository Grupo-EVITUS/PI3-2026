<?php
declare(strict_types=1);

use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este comando só pode ser executado no terminal.\n");
    exit(2);
}

$variables = ['INITIAL_ADMIN_NAME', 'INITIAL_ADMIN_EMAIL', 'INITIAL_ADMIN_PASSWORD'];
$values = [];
foreach ($variables as $variable) {
    $value = getenv($variable);
    if ($value === false || $value === '') {
        fwrite(STDERR, "Defina as variáveis de ambiente exigidas por um gerenciador seguro de segredos.\n");
        exit(2);
    }
    $values[$variable] = $value;
    putenv($variable);
    unset($_ENV[$variable], $_SERVER[$variable]);
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $id = InitialAdmin::create(
        Mongo::database(),
        $values['INITIAL_ADMIN_NAME'],
        $values['INITIAL_ADMIN_EMAIL'],
        $values['INITIAL_ADMIN_PASSWORD']
    );
    fwrite(STDOUT, "Conta inicial de coordenação criada. ID: " . (string)$id . "\n");
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(2);
} catch (Throwable $error) {
    fwrite(STDERR, "Não foi possível criar a conta inicial: " . $error::class . "\n");
    exit(1);
} finally {
    unset($values);
}
