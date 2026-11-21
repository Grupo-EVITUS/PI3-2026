<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/MailQueue.php';

if (strtolower(getenv('MAIL_MODE') ?: '') !== 'smtp') {
    fwrite(STDERR, "Configure MAIL_MODE=smtp (e SMTP_HOST) para o worker de e-mail.\n");
    exit(1);
}
$watch = in_array('--watch', $argv, true);
do {
    $database = Saa\Database\Mongo::database();
    $count = (new Saa\Database\MongoMailQueue($database))->process('sendQueuedEmail')
        + (new Saa\Database\MongoPasswordReset($database))->deliver('sendQueuedEmail');
    if (!$watch) {
        echo "Mensagens processadas: $count\n";
        break;
    }
    sleep(2);
} while (true);
