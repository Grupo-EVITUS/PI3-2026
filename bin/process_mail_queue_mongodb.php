<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/MailQueue.php';

if (strtolower(getenv('MAIL_MODE') ?: '') !== 'smtp') {
    fwrite(STDERR, "Configure MAIL_MODE=smtp (e SMTP_HOST) para o worker de e-mail.\n");
    exit(1);
}
$watch = in_array('--watch', $argv, true);
// Mostra no terminal por que um envio falhou. Registra só a causa: o destinatário, o assunto e o
// corpo trazem dados pessoais e links individuais e não vão para o terminal.
$send = static function (array $job): void {
    try {
        sendQueuedEmail($job);
    } catch (Throwable $error) {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Falha ao enviar e-mail: ' . $error->getMessage() . "\n");
        throw $error;
    }
};
do {
    $database = Saa\Database\Mongo::database();
    $count = (new Saa\Database\MongoMailQueue($database))->process($send)
        + (new Saa\Database\MongoPasswordReset($database))->deliver($send);
    if (!$watch) {
        echo "Mensagens processadas: $count\n";
        break;
    }
    if ($count > 0) {
        echo '[' . date('Y-m-d H:i:s') . "] Mensagens processadas: $count\n";
    }
    sleep(2);
} while (true);
