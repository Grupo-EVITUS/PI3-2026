<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Notifications;

use MongoDB\BSON\ObjectId;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Domain\NotificationStatus;
use Saa\Models\AuditEventModel;
use Saa\Models\AttendanceModel;
use Saa\Models\EmailNotificationModel;
use Saa\Models\MessageTemplateModel;
use Saa\Models\StudentContextModel;
use Saa\Services\AttendanceService;
use Saa\Services\AuditService;
use Saa\Services\Mail\MailerFactory;
use Saa\Services\Mail\MailerInterface;
use Saa\Services\MessageTemplateService;
use Saa\Services\NotificationService;

/** Dados escolares mínimos e montagem dos serviços para os testes de ausência e notificações (#29, #43). */
trait SchoolFixtures
{
    protected function notificationService(MailerInterface $mailer): NotificationService
    {
        $db = Mongo::database();
        return new NotificationService(
            new EmailNotificationModel($db),
            new MessageTemplateService(new MessageTemplateModel($db)),
            new MailerFactory($mailer),
            new AuditService(new AuditEventModel($db)),
        );
    }

    protected function attendanceService(MailerInterface $mailer): AttendanceService
    {
        $db = Mongo::database();
        return new AttendanceService(
            new AttendanceModel($db),
            new StudentContextModel($db),
            $this->notificationService($mailer),
            new AuditService(new AuditEventModel($db)),
        );
    }

    /** @param array<string, mixed> $document */
    protected function insertDocument(string $collection, array $document): ObjectId
    {
        $document['_id'] ??= new ObjectId();
        Mongo::collection($collection)->insertOne($document);
        return $document['_id'];
    }

    protected function seedUser(): ObjectId
    {
        $now = Bson::now();
        return $this->insertDocument(Collections::USERS, [
            'name' => 'Mariana AOE', 'email' => 'aoe@escola.local', 'email_normalized' => 'aoe@escola.local',
            'password_hash' => '$2y$10$' . str_repeat('a', 53), 'profile' => 'AOE', 'active' => true,
            'password_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function seedPeriod(string $start = '2026-02-02', string $end = '2026-12-18', bool $active = true, string $name = '2026'): ObjectId
    {
        $now = Bson::now();
        return $this->insertDocument(Collections::ACADEMIC_PERIODS, [
            'name' => $name, 'start_date' => Bson::day($start), 'end_date' => Bson::day($end),
            'attendance_policy' => ['min_attendance_percent' => 75, 'alert_below_percent' => 80],
            'active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function seedClass(ObjectId $period, string $code = '6A', string $name = '6º Ano A'): ObjectId
    {
        $now = Bson::now();
        return $this->insertDocument(Collections::CLASSES, [
            'academic_period_id' => $period, 'code' => $code, 'name' => $name, 'grade' => '6º ano',
            'shift' => 'MORNING', 'active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function seedGuardian(string $name, string $email, string $status = 'VALID', bool $active = true): ObjectId
    {
        $now = Bson::now();
        return $this->insertDocument(Collections::GUARDIANS, [
            'name' => $name, 'email' => $email, 'email_normalized' => strtolower($email), 'email_status' => $status,
            'phone' => null, 'active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @param list<array{ObjectId, string, bool, bool}> $links [responsável, parentesco, principal, recebe aviso] */
    protected function seedStudent(string $name, string $code, array $links, bool $active = true): ObjectId
    {
        $now = Bson::now();
        return $this->insertDocument(Collections::STUDENTS, [
            'name' => $name, 'student_code' => $code, 'birth_date' => Bson::day('2015-03-14'),
            'guardians' => array_map(static fn (array $link): array => [
                'guardian_id' => $link[0], 'relationship' => $link[1], 'is_primary' => $link[2], 'receives_absence_notifications' => $link[3],
            ], $links),
            'active' => $active, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    protected function seedEnrollment(ObjectId $student, ObjectId $class, ObjectId $period, string $start = '2026-02-02', ?string $end = null): ObjectId
    {
        return $this->insertDocument(Collections::ENROLLMENTS, [
            'student_id' => $student, 'class_id' => $class, 'academic_period_id' => $period,
            'start_date' => Bson::day($start), 'end_date' => $end === null ? null : Bson::day($end),
            'end_reason' => $end === null ? null : 'TRANSFER', 'active' => $end === null,
        ]);
    }

    /**
     * Notificação válida inserida diretamente (para fila e webhook).
     *
     * @param array<string, mixed> $overrides
     */
    protected function seedNotification(array $overrides = []): ObjectId
    {
        $now = Bson::now();
        return $this->insertDocument(Collections::EMAIL_NOTIFICATIONS, $overrides + [
            'attendance_id' => new ObjectId(),
            'student_id' => new ObjectId(),
            'guardian_id' => new ObjectId(),
            'recipient_email' => 'carlos@exemplo.com',
            'subject' => 'Comunicado de ausência',
            'body' => 'Olá. Justifique pelo link: {{justification_link}} (válido até {{expires_at}}).',
            'status' => NotificationStatus::PENDING->value,
            'attempts' => 0,
            'provider_message_id' => null,
            'justification_link' => ['token_hash' => hash('sha256', bin2hex(random_bytes(16))), 'expires_at' => $now, 'used_at' => null],
            'events' => [],
            'resend_of' => null,
            'locked_at' => null,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array<string, mixed> documento bruto (inclui token_hash) */
    protected function rawNotification(ObjectId $id): array
    {
        $document = Mongo::collection(Collections::EMAIL_NOTIFICATIONS)->findOne(['_id' => $id]);
        self::assertIsArray($document);
        return $document;
    }
}
