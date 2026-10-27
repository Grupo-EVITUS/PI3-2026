<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/MailQueue.php';

$watch = in_array('--watch', $argv, true);
do {
    $count = processMailQueue($pdo);
    if (!$watch) {
        echo "Mensagens processadas: $count\n";
        break;
    }
    sleep(2);
} while (true);
