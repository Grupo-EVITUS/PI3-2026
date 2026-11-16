<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Saa\Database\Mongo;
use Saa\Database\MongoPushQueue;
use Saa\Push\WebPush;

if (getenv('SAA_WEB_MODE') !== 'mongo_staff') {
    fwrite(STDERR, "Configure SAA_WEB_MODE=mongo_staff para o worker de push.\n");
    exit(1);
}
try {
    $pusher = WebPush::fromEnvironment();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
// Avisos sem aparelho que aceite o push vão para mail_queue; mantenha também o worker de e-mail ativo.
$send = static fn(array $subscription, string $payload, string $topic): int => $pusher->send($subscription, $payload, $topic);
$watch = in_array('--watch', $argv, true);
do {
    $count = (new MongoPushQueue(Mongo::database()))->process($send);
    if (!$watch) {
        echo "Notificações push processadas: $count\n";
        break;
    }
    sleep(2);
} while (true);
