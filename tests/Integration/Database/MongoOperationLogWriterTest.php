<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Mongo;
use Saa\Database\MongoOperationLogWriter;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function auditCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function auditRejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException|TypeError) {
        return;
    }
    throw new RuntimeException($message);
}

$database = Mongo::database('saa_operation_log_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($database);
    $logs = $database->operation_logs;
    $writer = new MongoOperationLogWriter($logs);
    $actor = new ObjectId();
    $entity = new ObjectId();

    $logId = $writer->append($actor, 'AUSENCIA_REGISTRADA', 'ausencia', $entity, 'Data 2026-11-13');
    $entry = $logs->findOne(['_id' => $logId]);
    auditCheck(is_array($entry), 'O log deve ser persistido.');
    auditCheck(($entry['user_id'] ?? null) instanceof ObjectId && (string)$entry['user_id'] === (string)$actor,
        'O ator deve ser persistido como ObjectId.');
    auditCheck(($entry['entity_id'] ?? null) === (string)$entity,
        'O ID da entidade deve ser serializado como string hexadecimal.');
    auditCheck(($entry['happened_at'] ?? null) instanceof UTCDateTime,
        'O instante deve ser armazenado como data BSON UTC.');
    auditCheck($entry['details'] === 'Data 2026-11-13', 'Detalhes devem ser preservados sem campos extras.');
    auditCheck(!array_key_exists('password_hash', $entry) && !array_key_exists('token', $entry),
        'O documento não deve conter credenciais nem tokens.');

    $systemLogId = $writer->append(null, 'JUSTIFICATIVA_ENVIADA', 'ausencia', null, 'Motivo DOENCA');
    $systemLog = $logs->findOne(['_id' => $systemLogId]);
    auditCheck(is_array($systemLog) && $systemLog['user_id'] === null && $systemLog['entity_id'] === null,
        'Ações públicas devem permitir ator e entidade nulos.');

    auditRejected(fn() => $writer->append($actor, "\n", 'ausencia', $entity),
        'Ação vazia/controlada deve ser rejeitada.');
    auditRejected(fn() => $writer->append($actor, 'EVENTO', 'ausencia', str_repeat('x', 129)),
        'ID de entidade acima do limite deve ser rejeitado.');
    auditRejected(fn() => $writer->append($actor, 'EVENTO', 'ausencia', null, str_repeat('x', 4097)),
        'Detalhes acima do limite devem ser rejeitados.');
    auditRejected(fn() => $writer->append($actor, "evento\xFF", 'ausencia', null),
        'Ação sem UTF-8 válido deve ser rejeitada antes da gravação.');
    auditRejected(fn() => $writer->append($actor, 'EVENTO', 'ausencia', "id\xFF"),
        'ID de entidade sem UTF-8 válido deve ser rejeitado antes da gravação.');
    auditRejected(fn() => $writer->append($actor, 'EVENTO', 'ausencia', null, "detalhe\xFF"),
        'Detalhes sem UTF-8 válido devem ser rejeitados antes da gravação.');
    auditRejected(fn() => $writer->append('id-invalido', 'EVENTO', 'ausencia', null),
        'Ator que não seja ObjectId não deve ser aceito.');

    $indexes = iterator_to_array($logs->listIndexes(), false);
    $dateIndex = null;
    foreach ($indexes as $index) {
        if ($index->getName() === 'logs_happened_at') {
            $dateIndex = $index;
            break;
        }
    }
    auditCheck($dateIndex !== null, 'Índice de consulta por data deve existir.');
    auditCheck(!isset($dateIndex['expireAfterSeconds']),
        'Logs de auditoria não devem expirar automaticamente.');
    auditCheck(!method_exists($writer, 'update') && !method_exists($writer, 'delete'),
        'O gravador deve expor somente inclusão, sem edição ou exclusão.');

    fwrite(STDOUT, "MongoOperationLogWriterTest: auditoria append-only passou.\n");
} finally {
    $database->drop();
}
4f3cc77db00e52bd094a706331f8471d9c2a4662
