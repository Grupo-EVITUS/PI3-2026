<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Saa\Database\Mongo;
use Saa\Database\MongoPushQueue;
use Saa\Push\WebPush;

try {
    $pusher = WebPush::fromEnvironment();
    $reminderHours = MongoPushQueue::reminderHoursFromEnvironment();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
// Avisos sem aparelho que aceite o push vão para mail_queue, assim como os lembretes de push
// entregue sem justificativa após PUSH_EMAIL_REMINDER_HOURS: mantenha também o worker de e-mail ativo.
$send = static fn(array $subscription, string $payload, string $topic): int => $pusher->send($subscription, $payload, $topic);
$watch = in_array('--watch', $argv, true);
do {
    $queue = new MongoPushQueue(Mongo::database(), $reminderHours);
    $count = $queue->process($send);
    $reminders = $reminderHours > 0 ? $queue->remindUnanswered() : 0;
    if (!$watch) {
        echo "Notificações push processadas: $count\n";
        echo "Lembretes por e-mail verificados: $reminders\n";
        break;
    }
    sleep(2);
} while (true);
