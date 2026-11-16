<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Mailer.php';

if (strtolower(getenv('MAIL_MODE') ?: '') !== 'smtp') {
    fwrite(STDERR, "Configure MAIL_MODE=smtp para o worker de e-mail.\n");
    exit(1);
}
$watch = in_array('--watch', $argv, true);
do {
    $count = (new Saa\Database\MongoMailQueue(Saa\Database\Mongo::database()))
        ->process('sendQueuedEmail');
    if (!$watch) {
        echo "Mensagens processadas: $count\n";
        break;
    }
    sleep(2);
} while (true);
