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

    $registration = new Saa\Database\MongoStaffRegistration($database);
    $staff = $registration->register($commitUserId, 'Funcionária', 'staff@example.org', 'Senha-de-teste-2026', 'AOE');
    transactionCheck($database->users->countDocuments(['_id' => $staff]) === 1
        && $database->operation_logs->countDocuments(['user_id' => $commitUserId, 'entity_id' => (string)$staff]) === 1,
        'Cadastro autorizado deve persistir funcionário e auditoria.');

    try {
        $registration->register($staff, 'Sem acesso', 'denied@example.org', 'Senha-de-teste-2026', 'AOE');
        throw new LogicException('Cadastro com perfil AOE deveria falhar.');
    } catch (RuntimeException $error) {
        transactionCheck($error->getMessage() === 'Cadastro permitido somente à coordenação ativa.',
            'A operação deveria falhar por autorização.');
    }

    // O validador temporário rejeita somente a inserção na coleção de auditoria.
    $database->command(['collMod' => 'operation_logs', 'validator' => ['action' => 'EVENTO_DE_TESTE']])->toArray();
    $before = $database->operation_logs->countDocuments();
    try {
        $registration->register($commitUserId, 'Falha controlada', 'failed@example.org', 'Senha-de-teste-2026', 'AOE');
        throw new LogicException('O banco deveria rejeitar o evento.');
    } catch (MongoDB\Driver\Exception\BulkWriteException $error) {
        transactionCheck($error->getCode() === 121, 'Esperada rejeição do validador da auditoria.');
    }
    transactionCheck($database->users->countDocuments(['email' => 'failed@example.org']) === 0
        && $database->operation_logs->countDocuments() === $before,
        'Falha da auditoria deve desfazer todo o cadastro.');

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
