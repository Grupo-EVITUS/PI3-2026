<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Notifications;

use MongoDB\BSON\ObjectId;
use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Core\Exceptions\ConflictException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\DefaultData;
use Saa\Database\Mongo;
use Saa\Models\StudentContextModel;
use Saa\Services\AttendanceService;
use Saa\Services\MessageTemplateService;
use Saa\Services\NotificationService;
use Saa\Tests\Integration\Database\FrequentQueriesTest;
use Saa\Tests\Support\MongoTestCase;

/**
 * Registro de ausência e criação dos e-mails (issue #29) contra o MongoDB real: CONSULTAS 1 e 2, regras de data e de
 * matrícula, duplicidade, PRESENT → ABSENT, destinatários válidos, token só no e-mail e só o hash no banco.
 */
final class AbsenceRegistrationTest extends MongoTestCase
{
    use SchoolFixtures;

    private ObjectId $user;
    private ObjectId $period;
    private ObjectId $class;
    private ObjectId $carlos;
    private ObjectId $student;
    private RecordingMailer $mailer;
    private AttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Clock::fix('2026-10-09T15:00:00Z'); // 12:00 em São Paulo
        Config::set('APP_TIMEZONE', 'America/Sao_Paulo');
        Config::set('APP_URL', 'https://saa.escola.test');
        Config::set('MAIL_DISPATCH', 'inline');
        Config::set('JUSTIFICATION_LINK_TTL_DAYS', null);
        Config::set('APP_SCHOOL_NAME', null);

        $this->user = $this->seedUser();
        $this->period = $this->seedPeriod();
        $this->class = $this->seedClass($this->period);
        $this->carlos = $this->seedGuardian('Carlos Souza', 'Carlos.Souza@exemplo.com');
        $marina = $this->seedGuardian('Marina Souza', 'marina@exemplo.com');
        $noNotices = $this->seedGuardian('Tio Sem Aviso', 'tio@exemplo.com');
        $bounced = $this->seedGuardian('Avó Bounce', 'avo@exemplo.com', 'BOUNCED');
        $this->student = $this->seedStudent('Ana Beatriz Souza', 'RA-1', [
            [$this->carlos, 'PAI', true, true],
            [$marina, 'MAE', false, true],
            [$noNotices, 'RESPONSAVEL', false, false],
        ]);
        // arquivar Marina depois de vinculada: responsável inativo não recebe; Bounce nem está vinculada a Ana
        Mongo::collection(Collections::GUARDIANS)->updateOne(['_id' => $marina], ['$set' => ['active' => false]]);
        $this->seedStudent('Outro Aluno', 'RA-2', [[$bounced, 'RESPONSAVEL', true, true]]);
        $this->seedEnrollment($this->student, $this->class, $this->period);

        $this->mailer = new RecordingMailer();
        $this->service = $this->attendanceService($this->mailer);
    }

    protected function tearDown(): void
    {
        foreach (['APP_TIMEZONE', 'APP_URL', 'MAIL_DISPATCH', 'JUSTIFICATION_LINK_TTL_DAYS', 'APP_SCHOOL_NAME'] as $key) {
            Config::set($key, null);
        }
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function notifications(): array
    {
        return Mongo::collection(Collections::EMAIL_NOTIFICATIONS)->find([], ['sort' => ['created_at' => 1, '_id' => 1]])->toArray();
    }

    private function auditCount(string $action): int
    {
        return Mongo::collection(Collections::AUDIT_EVENTS)->countDocuments(['action' => $action]);
    }

    public function testIssue29StudentContextUsesTheDocumentedQuery1Pipeline(): void
    {
        self::assertEquals(FrequentQueriesTest::studentContextPipeline($this->student), StudentContextModel::pipeline($this->student));

        $context = (new StudentContextModel($this->db()))->find($this->student);
        self::assertSame('Ana Beatriz Souza', $context['name'] ?? null);
        self::assertSame(['Carlos Souza'], array_column($context['guardians_to_notify'], 'name'), 'só ativos, VALID e que recebem aviso');
    }

    public function testIssue29RegistersTheAbsenceWithPendingJustificationAndClassSnapshot(): void
    {
        $result = $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user, 'Informado pela professora.');

        $record = Mongo::collection(Collections::ATTENDANCE_RECORDS)->findOne(['student_id' => $this->student]);
        self::assertIsArray($record);
        self::assertSame('ABSENT', $record['status']);
        self::assertSame('2026-10-09', Bson::ymd($record['date']));
        self::assertSame('6º Ano A', $record['class_name']);
        self::assertEquals($this->class, $record['class_id']);
        self::assertEquals($this->period, $record['academic_period_id']);
        self::assertEquals($this->user, $record['registered_by']);
        self::assertSame(['source' => 'INTERNAL_MESSAGE', 'note' => 'Informado pela professora.'], $record['details']);
        self::assertSame('PENDING', $record['justification']['status']);
        self::assertCount(1, $record['justification']['status_history']);
        self::assertEquals($this->user, $record['justification']['status_history'][0]['by_user_id']);
        self::assertFalse($result['converted_from_present']);
        self::assertSame(['id' => (string)$this->student, 'name' => 'Ana Beatriz Souza'], $result['student']);
        self::assertSame(1, $this->auditCount(AttendanceService::ACTION_ABSENCE_REGISTERED));
    }

    public function testIssue29OneNotificationOnlyForEachValidGuardianAndItIsSentInline(): void
    {
        $result = $this->service->registerAbsence((string)$this->student, '2026-10-08', (string)$this->user);

        $notifications = $this->notifications();
        self::assertCount(1, $notifications, 'inativo, BOUNCED e "não recebe aviso" ficam de fora');
        self::assertEquals($this->carlos, $notifications[0]['guardian_id']);
        self::assertSame('Carlos.Souza@exemplo.com', $notifications[0]['recipient_email']);
        self::assertSame('SENT', $notifications[0]['status']);
        self::assertSame(1, $notifications[0]['attempts']);
        self::assertSame('msg-1@teste.local', $notifications[0]['provider_message_id']);
        self::assertSame(['SENT'], array_column($notifications[0]['events'], 'type'));
        self::assertNull($notifications[0]['locked_at']);
        self::assertSame([['id' => (string)$notifications[0]['_id'], 'guardian_id' => (string)$this->carlos, 'guardian_name' => 'Carlos Souza', 'recipient_email' => 'Carlos.Souza@exemplo.com', 'status' => 'SENT']], $result['notifications']);
        self::assertSame([], $result['warnings']);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('Carlos.Souza@exemplo.com', $this->mailer->sent[0]->to);
        self::assertSame(1, $this->auditCount(NotificationService::ACTION_CREATED));
        self::assertSame(1, $this->auditCount(NotificationService::ACTION_SENT));
    }

    public function testIssue29RealTokenIsOnlyInTheSentEmailAndOnlyItsHashIsStored(): void
    {
        $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user, 'Chegou atrasada? Não veio.');

        $stored = $this->notifications()[0];
        $message = $this->mailer->sent[0];
        $token = $this->mailer->tokenOf($message);
        self::assertNotNull($token, 'o e-mail traz o link /justify/<token>');
        self::assertStringContainsString('https://saa.escola.test/justify/' . $token, $message->text);
        self::assertStringContainsString('https://saa.escola.test/justify/' . $token, (string)$message->html);
        self::assertSame(hash('sha256', $token), $stored['justification_link']['token_hash']);
        self::assertNull($stored['justification_link']['used_at']);
        self::assertSame('2026-11-08T15:00:00Z', Bson::toPlain($stored['justification_link']['expires_at']), 'validade padrão de 30 dias');

        self::assertStringNotContainsString($token, $stored['body'], 'o corpo gravado nunca tem o token');
        self::assertStringNotContainsString('/justify/', $stored['body']);
        self::assertStringContainsString(MessageTemplateService::LINK_MARKER, $stored['body']);
        $audit = json_encode(Mongo::collection(Collections::AUDIT_EVENTS)->find()->toArray());
        self::assertStringNotContainsString($token, (string)$audit);
        self::assertStringNotContainsString($stored['justification_link']['token_hash'], (string)$audit);

        // texto do modelo padrão em pt-BR com aluno, turma, data, observação e validade
        self::assertSame('Comunicado de ausência: Ana Beatriz Souza (09/10/2026)', $message->subject);
        foreach (['Olá, Carlos Souza.', 'Ana Beatriz Souza, da turma 6º Ano A, não compareceu às aulas em 09/10/2026.', 'Chegou atrasada? Não veio.', 'vale até 08/11/2026', 'Equipe escolar'] as $piece) {
            self::assertStringContainsString($piece, $message->text);
        }
        self::assertStringNotContainsString('{{', $message->text);
    }

    public function testIssue29TemplateFromTheDatabaseIsUsedAndUnknownVariablesStayEmpty(): void
    {
        DefaultData::apply($this->db());
        Mongo::collection(Collections::MESSAGE_TEMPLATES)->updateOne(['code' => DefaultData::ABSENCE_NOTICE], ['$set' => [
            'subject' => 'Falta de {{student_name}} {{segredo}}',
            'body' => 'Prezado(a) {{guardian_name}}, {{student_name}} faltou. {{password}}',
        ]]);

        $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user);

        $message = $this->mailer->sent[0];
        self::assertSame('Falta de Ana Beatriz Souza', $message->subject);
        self::assertStringStartsWith('Prezado(a) Carlos Souza, Ana Beatriz Souza faltou.', $message->text);
        self::assertMatchesRegularExpression('#Link para justificar a ausência: https://saa\.escola\.test/justify/[A-Za-z0-9_-]{43}$#', $message->text, 'sem o marcador no modelo, o link é acrescentado');
    }

    public function testIssue29NoValidRecipientStillRegistersTheAbsenceAndWarns(): void
    {
        $alone = $this->seedStudent('Bruno Sem Contato', 'RA-3', [[$this->seedGuardian('Pai Bounce', 'pai@exemplo.com', 'BOUNCED'), 'PAI', true, true]]);
        $this->seedEnrollment($alone, $this->class, $this->period);

        $result = $this->service->registerAbsence((string)$alone, '2026-10-09', (string)$this->user);

        self::assertSame(1, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments(['student_id' => $alone]));
        self::assertSame([], $result['notifications']);
        self::assertSame([], $this->notifications());
        self::assertStringContainsString('Nenhum responsável com e-mail válido', $result['warnings'][0] ?? '');
    }

    public function testIssue29DuplicateAbsenceIsAConflictAndKeepsTheFirstRecord(): void
    {
        $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user, 'primeira');

        try {
            $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user, 'segunda');
            self::fail('a segunda falta no mesmo dia deveria ser recusada');
        } catch (ConflictException $error) {
            self::assertSame('Já existe ausência registrada para este aluno nesta data.', $error->getMessage());
        }
        Mongo::collection(Collections::ATTENDANCE_RECORDS)->updateOne(['student_id' => $this->student], ['$set' => ['status' => 'EXCUSED']]);
        $this->expectException(ConflictException::class);
        try {
            $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user);
        } finally {
            self::assertSame(1, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));
            self::assertCount(1, $this->notifications(), 'nenhum e-mail novo para a falta repetida');
            self::assertSame('primeira', Mongo::collection(Collections::ATTENDANCE_RECORDS)->findOne([])['details']['note']);
        }
    }

    public function testIssue29PresenceOnTheSameDayIsConvertedIntoAnAbsence(): void
    {
        $enrollment = Mongo::collection(Collections::ENROLLMENTS)->findOne(['student_id' => $this->student]);
        $presentId = $this->insertDocument(Collections::ATTENDANCE_RECORDS, [
            'enrollment_id' => $enrollment['_id'], 'student_id' => $this->student, 'class_id' => $this->class,
            'class_name' => '6º Ano A', 'academic_period_id' => $this->period, 'date' => Bson::day('2026-10-07'),
            'status' => 'PRESENT', 'registered_by' => $this->user, 'justification' => null, 'created_at' => Bson::now(),
        ]);

        $result = $this->service->registerAbsence((string)$this->student, '2026-10-07', (string)$this->user, 'Saiu antes da chamada.');

        $record = Mongo::collection(Collections::ATTENDANCE_RECORDS)->findOne(['_id' => $presentId]);
        self::assertTrue($result['converted_from_present']);
        self::assertSame(1, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]), 'atualiza, não cria outro registro');
        self::assertSame('ABSENT', $record['status']);
        self::assertSame('PENDING', $record['justification']['status']);
        self::assertSame('Saiu antes da chamada.', $record['details']['note']);
        self::assertArrayHasKey('updated_at', $record);
        self::assertCount(1, $this->notifications());
    }

    public function testIssue29WithoutActiveEnrollmentNothingIsWritten(): void
    {
        $loose = $this->seedStudent('Caio Sem Turma', 'RA-4', [[$this->carlos, 'PAI', true, true]]);
        $this->seedEnrollment($loose, $this->class, $this->period, '2026-02-02', '2026-06-30'); // matrícula encerrada

        try {
            $this->service->registerAbsence((string)$loose, '2026-10-09', (string)$this->user);
            self::fail('sem matrícula ativa não há falta');
        } catch (ValidationException $error) {
            self::assertStringContainsString('não tem matrícula ativa', $error->errors()['student_id'] ?? '');
        }
        self::assertSame(0, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));
        self::assertSame([], $this->notifications());
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidDates(): array
    {
        return [
            'futura (amanhã)' => ['2026-10-10', 'date', 'não pode ser futura'],
            'antes do período letivo' => ['2026-01-15', 'date', 'fora do período letivo ativo (02/02/2026 a 18/12/2026)'],
            'data inexistente' => ['2026-02-30', 'date', 'Informe uma data válida.'],
            'formato errado' => ['09/10/2026', 'date', 'Informe uma data válida.'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDates')]
    public function testIssue29InvalidDatesAreValidationErrors(string $date, string $field, string $message): void
    {
        try {
            $this->service->registerAbsence((string)$this->student, $date, (string)$this->user);
            self::fail('data inválida deveria ser recusada');
        } catch (ValidationException $error) {
            self::assertStringContainsString($message, $error->errors()[$field] ?? '');
        }
        self::assertSame(0, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));
    }

    public function testIssue29DateBeforeTheEnrollmentOrInAnInactivePeriodIsRejected(): void
    {
        $late = $this->seedStudent('Duda Transferida', 'RA-5', [[$this->carlos, 'PAI', true, true]]);
        $this->seedEnrollment($late, $this->class, $this->period, '2026-09-01');
        $old = $this->seedPeriod('2025-02-03', '2025-12-19', false, '2025');
        $oldClass = $this->seedClass($old, '5A', '5º Ano A');
        $stale = $this->seedStudent('Enzo Período Antigo', 'RA-6', [[$this->carlos, 'PAI', true, true]]);
        $this->seedEnrollment($stale, $oldClass, $old, '2025-02-03');

        foreach ([[$late, '2026-08-31', 'anterior ao início da matrícula'], [$stale, '2025-10-09', 'não pertence ao período letivo ativo']] as [$student, $date, $message]) {
            try {
                $this->service->registerAbsence((string)$student, $date, (string)$this->user);
                self::fail('data fora da matrícula/período deveria ser recusada');
            } catch (ValidationException $error) {
                self::assertStringContainsString($message, $error->errors()['date'] ?? '');
            }
        }
        self::assertSame(0, Mongo::collection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));
    }

    public function testIssue29UnknownArchivedOrMalformedStudentIsAValidationError(): void
    {
        $archived = $this->seedStudent('Fábio Arquivado', 'RA-7', [[$this->carlos, 'PAI', true, true]], false);
        foreach ([(string)new ObjectId(), (string)$archived, 'abc', ''] as $id) {
            try {
                $this->service->registerAbsence($id, '2026-10-09', (string)$this->user);
                self::fail('aluno inválido deveria ser recusado');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('student_id', $error->errors());
            }
        }
    }

    public function testIssue29QueueModeOnlyEnqueuesAndInlineFailureSchedulesARetry(): void
    {
        Config::set('MAIL_DISPATCH', 'queue');
        $queued = $this->service->registerAbsence((string)$this->student, '2026-10-08', (string)$this->user);
        self::assertSame('PENDING', $queued['notifications'][0]['status']);
        self::assertSame([], $this->mailer->sent);
        self::assertContains('Os e-mails foram colocados na fila de envio.', $queued['warnings']);

        Config::set('MAIL_DISPATCH', 'inline');
        $this->mailer->failures = 1;
        $failed = $this->service->registerAbsence((string)$this->student, '2026-10-09', (string)$this->user);
        $notification = $this->notifications()[1];
        self::assertSame('FAILED', $failed['notifications'][0]['status']);
        self::assertSame('FAILED', $notification['status']);
        self::assertSame(1, $notification['attempts']);
        self::assertStringContainsString('421 4.3.2', $notification['last_error']);
        self::assertSame(Clock::now()->getTimestamp() + 300, Bson::dateTime($notification['next_attempt_at'])->getTimestamp(), 'nova tentativa em 5 minutos');
        self::assertStringContainsString('o sistema tentará de novo automaticamente', implode(' ', $failed['warnings']));
        self::assertSame(1, $this->auditCount(NotificationService::ACTION_FAILED));
    }
}
