<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Notifications;

use DateInterval;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\DefaultData;
use Saa\Database\Mongo;
use Saa\Models\EmailNotificationModel;
use Saa\Models\MessageTemplateModel;
use Saa\Tests\Support\MongoTestCase;

/**
 * Fila de e-mails (issue #29; base de #42): reserva atômica, tentativas com espera crescente, limite de 3, reserva
 * expirada, reenvio como nova notificação, eventos limitados, script bin/process_mail_queue.php e dados padrão.
 */
final class MailQueueTest extends MongoTestCase
{
    use SchoolFixtures;

    private const START = '2026-10-09T12:00:00Z';

    protected function setUp(): void
    {
        parent::setUp();
        Clock::fix(self::START);
        Config::set('APP_URL', 'https://saa.escola.test');
        Config::set('MAIL_DISPATCH', 'inline');
    }

    protected function tearDown(): void
    {
        foreach (['APP_URL', 'MAIL_DISPATCH'] as $key) {
            Config::set($key, null);
        }
        parent::tearDown();
    }

    private function advance(int $minutes): void
    {
        Clock::fix(Clock::now()->add(new DateInterval('PT' . $minutes . 'M')));
    }

    public function testIssue29TwoReservationsNeverTakeTheSameMessage(): void
    {
        $first = $this->seedNotification(['created_at' => Bson::date('2026-10-09T11:00:00Z')]);
        $second = $this->seedNotification(['created_at' => Bson::date('2026-10-09T11:01:00Z')]);
        $model = new EmailNotificationModel($this->db());
        $now = Bson::now();
        $stale = Bson::date('2026-10-09T11:55:00Z');

        $a = $model->reserveNext($now, $stale);
        $b = $model->reserveNext($now, $stale);
        $c = $model->reserveNext($now, $stale);

        self::assertEquals($first, $a['_id'] ?? null, 'a mais antiga primeiro');
        self::assertEquals($second, $b['_id'] ?? null);
        self::assertNull($c, 'nada sobra para uma terceira reserva');
        self::assertArrayNotHasKey('token_hash', $a['justification_link'], 'leituras nunca trazem o hash');
        self::assertNull($model->reserveById($first, $now, $stale), 'reservada não pode ser reservada de novo');
    }

    public function testIssue29ExpiredReservationIsTakenOverAfterFiveMinutes(): void
    {
        $id = $this->seedNotification(['locked_at' => Bson::date('2026-10-09T11:57:00Z')]);
        $mailer = new RecordingMailer();
        $service = $this->notificationService($mailer);

        self::assertSame(0, $service->processQueue()['processed'], 'reserva de 3 minutos ainda vale');
        $this->advance(3);
        self::assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $service->processQueue());
        self::assertSame('SENT', $this->rawNotification($id)['status']);
    }

    public function testIssue29FailuresRetryWithGrowingWaitAndGiveUpAfterThreeAttempts(): void
    {
        $id = $this->seedNotification();
        $mailer = new RecordingMailer(-1); // sempre falha
        $service = $this->notificationService($mailer);

        self::assertSame(['processed' => 1, 'sent' => 0, 'failed' => 1], $service->processQueue());
        $doc = $this->rawNotification($id);
        self::assertSame(['FAILED', 1], [$doc['status'], $doc['attempts']]);
        self::assertSame('2026-10-09T12:05:00Z', Bson::toPlain($doc['next_attempt_at']), '1ª falha: espera 5 minutos');
        self::assertStringContainsString('421 4.3.2', $doc['last_error']);

        $this->advance(4);
        self::assertSame(0, $service->processQueue()['processed'], 'antes do prazo não tenta de novo');
        $this->advance(1);
        self::assertSame(1, $service->processQueue()['failed']);
        $doc = $this->rawNotification($id);
        self::assertSame(2, $doc['attempts']);
        self::assertSame('2026-10-09T12:35:00Z', Bson::toPlain($doc['next_attempt_at']), '2ª falha: espera 30 minutos');

        $this->advance(30);
        self::assertSame(1, $service->processQueue()['failed']);
        $doc = $this->rawNotification($id);
        self::assertSame(['FAILED', 3], [$doc['status'], $doc['attempts']]);
        self::assertNull($doc['next_attempt_at'], '3ª falha: desiste');
        self::assertNull($doc['locked_at']);
        self::assertSame(['FAILED', 'FAILED', 'FAILED'], array_column($doc['events'], 'type'));

        $this->advance(24 * 60);
        self::assertSame(0, $service->processQueue()['processed'], 'nunca passa de 3 tentativas');
        self::assertSame(3, $mailer->calls);
        $audit = Mongo::collection(Collections::AUDIT_EVENTS)->find(['action' => 'notification.failed'], ['sort' => ['at' => 1]])->toArray();
        self::assertSame([false, false, true], array_map(static fn (array $event): bool => $event['details']['final'], $audit));
    }

    public function testIssue29RetrySucceedsWithANewTokenAndInvalidatesThePreviousHash(): void
    {
        $id = $this->seedNotification();
        $mailer = new RecordingMailer(1);
        $service = $this->notificationService($mailer);

        $service->processQueue();
        $hashAfterFailure = $this->rawNotification($id)['justification_link']['token_hash'];
        $this->advance(5);
        self::assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $service->processQueue());

        $doc = $this->rawNotification($id);
        $token = $mailer->tokenOf();
        self::assertSame('SENT', $doc['status']);
        self::assertSame(2, $doc['attempts']);
        self::assertNull($doc['last_error']);
        self::assertNull($doc['next_attempt_at']);
        self::assertSame(['FAILED', 'SENT'], array_column($doc['events'], 'type'));
        self::assertSame(hash('sha256', (string)$token), $doc['justification_link']['token_hash']);
        self::assertNotSame($hashAfterFailure, $doc['justification_link']['token_hash'], 'cada tentativa gera um token novo');
        self::assertSame('2026-11-08T12:05:00Z', Bson::toPlain($doc['justification_link']['expires_at']));
        self::assertStringContainsString('(válido até 08/11/2026)', $mailer->sent[0]->text);
        self::assertStringNotContainsString((string)$token, $doc['body']);
    }

    public function testIssue29ProcessQueueRespectsTheLimitAndIgnoresFinishedMessages(): void
    {
        foreach (range(1, 4) as $i) {
            $this->seedNotification(['created_at' => Bson::date("2026-10-09T11:0{$i}:00Z")]);
        }
        $this->seedNotification(['status' => 'SENT', 'attempts' => 1]);
        $this->seedNotification(['status' => 'OPENED', 'attempts' => 1]);
        $this->seedNotification(['status' => 'FAILED', 'attempts' => 3, 'next_attempt_at' => null]);
        $this->seedNotification(['status' => 'PENDING', 'next_attempt_at' => Bson::date('2026-10-09T13:00:00Z')]);
        $mailer = new RecordingMailer();
        $service = $this->notificationService($mailer);

        self::assertSame(['processed' => 3, 'sent' => 3, 'failed' => 0], $service->processQueue(3));
        self::assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $service->processQueue(50));
        self::assertSame(0, $service->processQueue(50)['processed']);
        self::assertCount(4, $mailer->sent);
    }

    public function testIssue42ResendCreatesANewNotificationAndKeepsTheOriginalIntact(): void
    {
        $original = $this->seedNotification(['status' => 'SENT', 'attempts' => 1, 'provider_message_id' => 'orig@teste', 'events' => [['type' => 'SENT', 'occurred_at' => Bson::now(), 'detail' => null]]]);
        $before = $this->rawNotification($original);
        $mailer = new RecordingMailer();
        $user = $this->seedUser();

        $resent = $this->notificationService($mailer)->resend((string)$original, (string)$user);

        self::assertNotEquals($original, $resent['_id']);
        self::assertEquals($original, $resent['resend_of']);
        self::assertEquals($user, $resent['created_by']);
        self::assertSame('SENT', $resent['status']);
        self::assertEquals($before, $this->rawNotification($original), 'a original não muda');
        self::assertNotSame($before['justification_link']['token_hash'], $this->rawNotification($resent['_id'])['justification_link']['token_hash']);
        self::assertSame(hash('sha256', (string)$mailer->tokenOf()), $this->rawNotification($resent['_id'])['justification_link']['token_hash']);
        self::assertSame(2, Mongo::collection(Collections::EMAIL_NOTIFICATIONS)->countDocuments([]));
    }

    public function testIssue43EventsAreBoundedToTheThirtyMostRecent(): void
    {
        $id = $this->seedNotification(['status' => 'SENT', 'attempts' => 1]);
        $service = $this->notificationService(new RecordingMailer());

        foreach (range(1, 35) as $i) {
            $service->applyProviderEvent(['notification_id' => (string)$id, 'event' => 'DELIVERED', 'detail' => "evento {$i}"]);
        }

        $events = $this->rawNotification($id)['events'];
        self::assertCount(30, $events);
        self::assertSame('evento 6', $events[0]['detail']);
        self::assertSame('evento 35', $events[29]['detail']);
    }

    public function testIssue29TwoConcurrentQueueProcessesSendEachMessageExactlyOnce(): void
    {
        $ids = [];
        foreach (range(1, 12) as $i) {
            $ids[] = $this->seedNotification(['created_at' => new UTCDateTime((1_760_000_000 + $i) * 1000)]);
        }
        Clock::fix(null);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa_queue_' . bin2hex(random_bytes(6));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'test',
            'MONGODB_DB' => self::databaseName(),
            'MAIL_MODE' => 'file',
            'MAIL_FILE_DIR' => $directory,
            'APP_URL' => 'https://saa.escola.test',
        ]);
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            array_push($command, '-c', $ini);
        }
        array_push($command, Config::basePath('bin/process_mail_queue.php'), '--limit=50');

        try {
            $processes = [];
            for ($n = 0; $n < 2; $n++) { // dois processadores ao mesmo tempo sobre a mesma fila
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Config::basePath(), $env);
                self::assertIsResource($process);
                $processes[] = [$process, $pipes];
            }
            $outputs = [];
            foreach ($processes as [$process, $pipes]) {
                $outputs[] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), implode("\n", $outputs));
            }

            $files = glob($directory . DIRECTORY_SEPARATOR . '*.eml') ?: [];
            self::assertCount(12, $files, 'um arquivo por mensagem: ninguém enviou duas vezes');
            $total = 0;
            foreach ($outputs as $output) {
                self::assertMatchesRegularExpression('/Mensagens processadas: (\d+) \(enviadas: \1, com falha: 0\)/', $output);
                preg_match('/Mensagens processadas: (\d+)/', $output, $match);
                $total += (int)$match[1];
            }
            self::assertSame(12, $total);
            foreach ($ids as $id) {
                $doc = $this->rawNotification($id);
                self::assertSame(['SENT', 1, 1], [$doc['status'], $doc['attempts'], count($doc['events'])]);
            }
            $eml = (string)file_get_contents($files[0]);
            self::assertMatchesRegularExpression('#https://saa\.escola\.test/justify/[A-Za-z0-9_-]{43}#', quoted_printable_decode($eml));
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testIssue29QueueScriptRejectsUnknownOptions(): void
    {
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            array_push($command, '-c', $ini);
        }
        array_push($command, Config::basePath('bin/process_mail_queue.php'), '--limit=0');
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Config::basePath());
        self::assertIsResource($process);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(2, proc_close($process));
        self::assertStringContainsString('Opção inválida: --limit=0', (string)$err);
    }

    public function testIssue29DefaultTemplateIsCreatedOnceAndNeverOverwritten(): void
    {
        self::assertSame(['modelo de e-mail ABSENCE_NOTICE'], DefaultData::apply($this->db()));
        self::assertSame([], DefaultData::apply($this->db()), 'idempotente');

        $template = (new MessageTemplateModel($this->db()))->findActiveByCode(DefaultData::ABSENCE_NOTICE);
        self::assertIsArray($template);
        self::assertSame(DefaultData::ABSENCE_NOTICE_BODY, $template['body']);
        self::assertSame(DefaultData::ABSENCE_NOTICE_VARIABLES, $template['variables']);

        Mongo::collection(Collections::MESSAGE_TEMPLATES)->updateOne(['code' => DefaultData::ABSENCE_NOTICE], ['$set' => ['subject' => 'Texto da escola']]);
        DefaultData::apply($this->db());
        self::assertSame('Texto da escola', Mongo::collection(Collections::MESSAGE_TEMPLATES)->findOne(['code' => DefaultData::ABSENCE_NOTICE])['subject']);
        self::assertSame(1, Mongo::collection(Collections::MESSAGE_TEMPLATES)->countDocuments([]));
    }

    public function testIssue29NotificationIdThatDoesNotExistCannotBeResent(): void
    {
        $this->expectException(\Saa\Core\Exceptions\NotFoundException::class);
        $this->notificationService(new RecordingMailer())->resend((string)new ObjectId(), null);
    }
}
