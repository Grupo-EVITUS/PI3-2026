<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Mongo;
use Saa\Database\MongoOperationLogWriter;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function transactionCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = Mongo::database('saa_transaction_test_' . bin2hex(random_bytes(8)));
$session = null;
try {
    Schema::apply($database);
    $auditWriter = new MongoOperationLogWriter($database->operation_logs);
    $email = 'rollback-' . bin2hex(random_bytes(6)) . '@example.org';
    $userId = new ObjectId();
    $session = Mongo::client()->startSession();
    $session->startTransaction();

    $database->users->insertOne([
        '_id' => $userId,
        'name' => 'Usuário transacional',
        'email' => $email,
        'email_key' => Schema::emailKey($email),
        'password_hash' => password_hash('Senha-Transacao-2026!', PASSWORD_DEFAULT),
        'profile' => 'COORDENACAO',
        'active' => true,
        'created_at' => new UTCDateTime(),
    ], ['session' => $session]);
    $rollbackLogId = $auditWriter->append(
        $userId, 'USUARIO_CRIADO', 'usuario', $userId, 'Cadastro transacional de teste', $session
    );
    $session->abortTransaction();

    transactionCheck($database->users->findOne(['_id' => $userId]) === null,
        'Abortar a transação deve remover a inserção do usuário.');
    transactionCheck($database->operation_logs->findOne(['_id' => $rollbackLogId]) === null,
        'Abortar a transação deve remover também o evento de auditoria.');

    $commitEmail = 'commit-' . bin2hex(random_bytes(6)) . '@example.org';
    $commitUserId = new ObjectId();
    $session->startTransaction();
    $database->users->insertOne([
        '_id' => $commitUserId,
        'name' => 'Usuário transacional confirmado',
        'email' => $commitEmail,
        'email_key' => Schema::emailKey($commitEmail),
        'password_hash' => password_hash('Senha-Transacao-2026!', PASSWORD_DEFAULT),
        'profile' => 'COORDENACAO',
        'active' => true,
        'created_at' => new UTCDateTime(),
    ], ['session' => $session]);
    $commitLogId = $auditWriter->append(
        $commitUserId, 'USUARIO_CRIADO', 'usuario', $commitUserId, 'Cadastro transacional de teste', $session
    );
    $session->commitTransaction();

    transactionCheck($database->users->findOne(['_id' => $commitUserId]) !== null,
        'Confirmar a transação deve persistir o usuário.');
    transactionCheck($database->operation_logs->findOne(['_id' => $commitLogId]) !== null,
        'Confirmar a transação deve persistir o evento correspondente.');

    fwrite(STDOUT, "MongoTransactionTest: commit e rollback multi-coleção passaram.\n");
} finally {
    try {
        if ($session instanceof MongoDB\Driver\Session) {
            $session->endSession();
        }
    } finally {
        $database->drop();
    }
}
