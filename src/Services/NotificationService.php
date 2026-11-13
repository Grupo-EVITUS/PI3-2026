<?php
declare(strict_types=1);

namespace Saa\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Validator;
use Saa\Database\Bson;
use Saa\Database\Schema;
use Saa\Domain\NotificationEventType;
use Saa\Domain\NotificationStatus;
use Saa\Models\EmailNotificationModel;
use Saa\Services\Mail\MailerFactory;
use Saa\Services\Mail\MailException;
use Saa\Services\Mail\MailMessage;
use Saa\Support\Dates;
use Saa\Support\Email;
use Throwable;

/**
 * E-mails de ausência (issue #29; base de #42 reenvio e #43 eventos do provedor).
 *
 * Ciclo de uma notificação (docs/ARQUITETURA.md, seção 11):
 *  1. createForAbsence(): UMA notificação por responsável válido, status PENDING, attempts 0, events [], texto
 *     renderizado com os marcadores {{justification_link}}/{{expires_at}} e justification_link com o hash de um
 *     token descartável (o validator exige o campo e recusa "/justify/<token>" no corpo gravado);
 *  2. envio (inline logo após o registro, ou pela fila — bin/process_mail_queue.php): a mensagem é reservada com
 *     findOneAndUpdate (locked_at); a CADA tentativa um token novo é gerado (32 bytes, base64url), só o SHA-256 e a
 *     validade (JUSTIFICATION_LINK_TTL_DAYS, padrão 30) são gravados, e o link absoluto /justify/<token> entra apenas
 *     na mensagem enviada;
 *  3. sucesso: SENT + provider_message_id + evento SENT; falha: FAILED, attempts + 1, last_error sem segredos e nova
 *     tentativa em 5 e depois 30 minutos; na 3ª falha next_attempt_at = null (desiste);
 *  4. webhook do provedor (applyProviderEvent): SENT < DELIVERED < OPENED, FAILED nunca sobrescreve DELIVERED/OPENED.
 * Reenvio = NOVA notificação com resend_of apontando para a original (a antiga fica intacta).
 */
final class NotificationService
{
    public const ACTION_CREATED = 'notification.created';
    public const ACTION_SENT = 'notification.sent';
    public const ACTION_FAILED = 'notification.failed';
    public const ACTION_PROVIDER_EVENT = 'notification.provider_event';
    public const ENTITY = 'email_notifications';

    /** Espera (minutos) antes da 2ª e da 3ª tentativa. */
    public const RETRY_DELAYS_MINUTES = [5, 30];
    /** Reserva da fila: depois deste tempo uma mensagem "presa" (processo que caiu) pode ser retomada. */
    public const LOCK_MINUTES = 5;
    public const DEFAULT_TTL_DAYS = 30;

    /** Eventos aceitos do provedor e os status que cada um pode substituir (o status nunca regride). */
    private const PROVIDER_TRANSITIONS = [
        'SENT' => ['PENDING', 'FAILED'],
        'DELIVERED' => ['PENDING', 'FAILED', 'SENT'],
        'OPENED' => ['PENDING', 'FAILED', 'SENT', 'DELIVERED'],
        'FAILED' => ['PENDING', 'SENT', 'FAILED'],
    ];

    public function __construct(
        private readonly EmailNotificationModel $notifications,
        private readonly MessageTemplateService $templates,
        private readonly MailerFactory $mailers,
        private readonly AuditService $audit,
    ) {
    }

    // ------------------------------------------------------------------ criação

    /**
     * Cria (e, com MAIL_DISPATCH=inline, envia) os avisos de uma ausência.
     *
     * @param array<string, mixed> $attendance registro de attendance_records (com _id, student_id, date, class_name, details)
     * @param array<string, mixed> $student aluno (name)
     * @param list<array<string, mixed>> $guardians responsáveis a avisar (_id, name, email) — CONSULTA 1
     * @return array{notifications: list<array<string, mixed>>, warnings: list<string>}
     */
    public function notifyAbsence(array $attendance, array $student, array $guardians, ?ObjectId $createdBy): array
    {
        $warnings = [];
        $created = [];
        foreach ($guardians as $guardian) {
            $email = is_string($guardian['email'] ?? null) ? trim($guardian['email']) : '';
            $name = is_string($guardian['name'] ?? null) ? $guardian['name'] : 'Responsável';
            if (!Email::isValid($email) || !MailMessage::isValidAddress($email)) {
                $warnings[] = "O e-mail cadastrado de {$name} é inválido: nenhum aviso foi criado para este responsável.";
                continue;
            }
            $rendered = $this->templates->renderAbsenceNotice([
                'guardian_name' => $name,
                'student_name' => is_string($student['name'] ?? null) ? $student['name'] : '',
                'class_name' => is_string($attendance['class_name'] ?? null) ? $attendance['class_name'] : '',
                'absence_date' => Dates::formatDate($attendance['date'] ?? null),
                'note' => is_string($attendance['details']['note'] ?? null) ? $attendance['details']['note'] : '',
            ]);
            $document = $this->newDocument($attendance['_id'], $attendance['student_id'], $guardian['_id'], $email, $rendered['subject'], $rendered['body'], null, $createdBy);
            $this->notifications->insert($document);
            $this->audit->record(self::ACTION_CREATED, self::ENTITY, $document['_id'], [
                'attendance_id' => (string)$attendance['_id'],
                'guardian_id' => (string)$guardian['_id'],
            ]);
            $created[] = ['document' => $document, 'guardian_name' => $name];
        }

        if ($guardians === [] || $created === []) {
            $warnings[] = 'Nenhum responsável com e-mail válido recebe avisos deste aluno: a ausência foi registrada, mas nenhum e-mail foi enviado.';
        }

        $statuses = $this->dispatchCreated(array_map(static fn (array $item): ObjectId => $item['document']['_id'], $created));
        $summaries = [];
        foreach ($created as $item) {
            $id = (string)$item['document']['_id'];
            $status = $statuses[$id] ?? NotificationStatus::PENDING->value;
            $summaries[] = [
                'id' => $id,
                'guardian_id' => (string)$item['document']['guardian_id'],
                'guardian_name' => $item['guardian_name'],
                'recipient_email' => $item['document']['recipient_email'],
                'status' => $status,
            ];
            if ($status === NotificationStatus::FAILED->value) {
                $warnings[] = "Não foi possível enviar agora o e-mail para {$item['document']['recipient_email']}; o sistema tentará de novo automaticamente.";
            }
        }
        if ($created !== [] && !self::dispatchesInline()) {
            $warnings[] = 'Os e-mails foram colocados na fila de envio.';
        }
        return ['notifications' => $summaries, 'warnings' => $warnings];
    }

    /**
     * Reenvio (base da issue #42): cria uma NOVA notificação com o mesmo destinatário e texto, resend_of = original,
     * e um link novo no envio. A original não é alterada.
     *
     * @return array<string, mixed> nova notificação (sem token_hash), já com o status após o envio inline
     * @throws NotFoundException notificação original inexistente
     */
    public function resend(string $notificationId, ?string $userId): array
    {
        $original = $this->notifications->findById(Bson::objectId($notificationId));
        if ($original === null) {
            throw new NotFoundException('Comunicação não encontrada.');
        }
        $document = $this->newDocument(
            $original['attendance_id'],
            $original['student_id'],
            $original['guardian_id'],
            (string)$original['recipient_email'],
            (string)$original['subject'],
            (string)$original['body'],
            $original['_id'],
            Bson::objectId($userId),
        );
        $this->notifications->insert($document);
        $this->audit->record(self::ACTION_CREATED, self::ENTITY, $document['_id'], [
            'attendance_id' => (string)$original['attendance_id'],
            'guardian_id' => (string)$original['guardian_id'],
            'resend_of' => (string)$original['_id'],
        ]);
        $this->dispatchCreated([$document['_id']]);
        return $this->notifications->findById($document['_id']) ?? $document;
    }

    // ------------------------------------------------------------------ consulta (API JSON)

    /**
     * Histórico de comunicações das ausências informadas (mais recentes primeiro), pronto para JSON.
     *
     * @param list<ObjectId> $attendanceIds
     * @return list<array<string, mixed>>
     */
    public function historyOf(array $attendanceIds): array
    {
        return array_map(self::present(...), $this->notifications->findByAttendances($attendanceIds));
    }

    /**
     * Notificação → dados públicos (lista branca): status, tentativas, eventos e validade do link. Nunca inclui
     * token_hash, o corpo do e-mail nem a reserva da fila.
     *
     * @param array<string, mixed> $notification
     * @return array<string, mixed>
     */
    public static function present(array $notification): array
    {
        $link = is_array($notification['justification_link'] ?? null) ? $notification['justification_link'] : [];
        $events = [];
        foreach ((array)($notification['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $events[] = [
                'type' => (string)($event['type'] ?? ''),
                'occurred_at' => self::instant($event['occurred_at'] ?? null),
                'detail' => is_string($event['detail'] ?? null) ? $event['detail'] : null,
            ];
        }
        $id = static fn (mixed $value): ?string => $value instanceof ObjectId ? (string)$value : null;
        return [
            'id' => (string)$notification['_id'],
            'attendance_id' => $id($notification['attendance_id'] ?? null),
            'student_id' => $id($notification['student_id'] ?? null),
            'guardian_id' => $id($notification['guardian_id'] ?? null),
            'recipient_email' => (string)($notification['recipient_email'] ?? ''),
            'subject' => (string)($notification['subject'] ?? ''),
            'status' => (string)($notification['status'] ?? ''),
            'attempts' => (int)($notification['attempts'] ?? 0),
            'provider_message_id' => is_string($notification['provider_message_id'] ?? null) ? $notification['provider_message_id'] : null,
            'last_error' => is_string($notification['last_error'] ?? null) ? $notification['last_error'] : null,
            'next_attempt_at' => self::instant($notification['next_attempt_at'] ?? null),
            'resend_of' => $id($notification['resend_of'] ?? null),
            'link_expires_at' => self::instant($link['expires_at'] ?? null),
            'link_used_at' => self::instant($link['used_at'] ?? null),
            'events' => $events,
            'created_by' => $id($notification['created_by'] ?? null),
            'created_at' => self::instant($notification['created_at'] ?? null),
            'updated_at' => self::instant($notification['updated_at'] ?? null),
        ];
    }

    /** UTCDateTime → ISO-8601 em UTC ("2026-10-09T15:00:00Z"); outros valores → null. */
    private static function instant(mixed $value): ?string
    {
        return $value instanceof UTCDateTime ? (string)Bson::toPlain($value) : null;
    }

    // ------------------------------------------------------------------ envio

    /** MAIL_DISPATCH: inline (padrão) envia logo após criar; queue só enfileira. */
    public static function dispatchesInline(): bool
    {
        return strtolower(trim(Config::string('MAIL_DISPATCH', 'inline'))) !== 'queue';
    }

    /**
     * Processa a fila: reserva e envia até $limit mensagens prontas.
     *
     * @return array{processed: int, sent: int, failed: int}
     */
    public function processQueue(int $limit = 50): array
    {
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0];
        for ($i = 0; $i < max(0, $limit); $i++) {
            $now = Bson::now();
            $notification = $this->notifications->reserveNext($now, self::staleBefore());
            if ($notification === null) {
                break;
            }
            $status = $this->deliver($notification);
            if ($status === null) {
                continue;
            }
            $result['processed']++;
            $result[$status === NotificationStatus::SENT->value ? 'sent' : 'failed']++;
        }
        return $result;
    }

    /**
     * Envia uma notificação JÁ RESERVADA (locked_at). Gera o token desta tentativa, grava só o hash e envia.
     *
     * @param array<string, mixed> $notification
     * @return string|null status final (SENT | FAILED) ou null se a reserva foi perdida
     */
    public function deliver(array $notification): ?string
    {
        $id = $notification['_id'];
        $lockedAt = $notification['locked_at'] ?? null;
        if (!$id instanceof ObjectId || !$lockedAt instanceof UTCDateTime) {
            throw new InvalidArgumentException('A notificação precisa estar reservada antes do envio.');
        }
        $token = self::newToken();
        $expiresAt = Clock::now()->add(new DateInterval('P' . self::ttlDays() . 'D'));
        if (!$this->notifications->replaceJustificationLink($id, $lockedAt, hash('sha256', $token), new UTCDateTime($expiresAt))) {
            return null;
        }
        $deferred = [
            'justification_link' => absolute_url('/justify/' . $token),
            'expires_at' => $expiresAt->setTimezone(new DateTimeZone(Config::timezone()))->format('d/m/Y'),
        ];
        $attempt = (int)($notification['attempts'] ?? 0) + 1;

        try {
            $subject = $this->templates->finalize((string)$notification['subject'], $deferred);
            $text = $this->templates->finalize((string)$notification['body'], $deferred);
            $message = new MailMessage((string)$notification['recipient_email'], $subject, $text, MessageTemplateService::toHtml($text, $subject));
            $providerId = $this->mailers->mailer()->send($message);
        } catch (Throwable $error) {
            $reason = $error instanceof MailException || $error instanceof InvalidArgumentException
                ? $error->getMessage()
                : 'Erro inesperado no envio do e-mail.';
            if (!$error instanceof MailException && !$error instanceof InvalidArgumentException) {
                error_log(sprintf('[SAA] Falha inesperada ao enviar a notificação %s: %s: %s', (string)$id, $error::class, $error->getMessage()));
            }
            $final = $attempt >= Schema::MAX_NOTIFICATION_ATTEMPTS;
            $next = $final ? null : new UTCDateTime(Clock::now()->add(new DateInterval('PT' . self::RETRY_DELAYS_MINUTES[min($attempt, count(self::RETRY_DELAYS_MINUTES)) - 1] . 'M')));
            $this->notifications->markFailed($id, $reason, $next, Bson::now());
            $this->audit->record(self::ACTION_FAILED, self::ENTITY, $id, [
                'attempt' => $attempt,
                'final' => $final,
                'error' => mb_substr($reason, 0, 300),
            ]);
            return NotificationStatus::FAILED->value;
        }

        $this->notifications->markSent($id, $providerId, Bson::now());
        $this->audit->record(self::ACTION_SENT, self::ENTITY, $id, ['attempt' => $attempt, 'provider_message_id' => $providerId]);
        return NotificationStatus::SENT->value;
    }

    // ------------------------------------------------------------------ webhook do provedor (#43)

    /**
     * Registra um evento do provedor de e-mail.
     *
     * @param array<string, mixed> $input notification_id | provider_message_id, event, occurred_at?, detail?
     * @return array<string, mixed> notificação atualizada (sem token_hash)
     * @throws ValidationException dados inválidos
     * @throws NotFoundException notificação não encontrada
     */
    public function applyProviderEvent(array $input): array
    {
        $validator = Validator::make($input, ['event' => 'o evento'])
            ->required('event')
            ->in('event', array_keys(self::PROVIDER_TRANSITIONS))
            ->objectId('notification_id')
            ->string('provider_message_id', 255, 1)
            ->string('occurred_at', 40)
            ->string('detail', 500, 0, true);
        $data = $validator->validated();
        $blank = static fn (mixed $value): bool => $value === null || (is_string($value) && trim($value) === '');
        if ($blank($input['notification_id'] ?? null) && $blank($input['provider_message_id'] ?? null)) {
            $validator->addError('notification_id', 'Informe notification_id ou provider_message_id.');
        }
        $occurredAt = Bson::now();
        if (isset($data['occurred_at'])) {
            $parsed = self::parseInstant($data['occurred_at']);
            if ($parsed === null) {
                $validator->addError('occurred_at', 'Informe a data no formato ISO-8601 (ex.: 2026-10-09T12:00:00Z).');
            } else {
                $occurredAt = $parsed;
            }
        }
        $validator->validate();

        $notification = isset($data['notification_id'])
            ? $this->notifications->findById($data['notification_id'])
            : $this->notifications->findByProviderMessageId((string)($data['provider_message_id'] ?? ''));
        if ($notification === null) {
            throw new NotFoundException('Notificação não encontrada.');
        }

        $event = (string)$data['event'];
        $detail = isset($data['detail']) && $data['detail'] !== '' ? (string)$data['detail'] : null;
        $updated = $this->notifications->applyProviderEvent(
            $notification['_id'],
            NotificationEventType::from($event)->value,
            NotificationStatus::from($event)->value,
            self::PROVIDER_TRANSITIONS[$event],
            $occurredAt,
            $detail,
            Bson::now(),
        );
        if ($updated === null) {
            throw new NotFoundException('Notificação não encontrada.');
        }
        $this->audit->record(self::ACTION_PROVIDER_EVENT, self::ENTITY, $notification['_id'], [
            'event' => $event,
            'previous_status' => (string)$notification['status'],
            'status' => (string)$updated['status'],
        ]);
        return $updated;
    }

    // ------------------------------------------------------------------ utilitários

    /** Token público: 32 bytes aleatórios em base64url (43 caracteres). */
    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function ttlDays(): int
    {
        return max(1, min(365, Config::int('JUSTIFICATION_LINK_TTL_DAYS', self::DEFAULT_TTL_DAYS)));
    }

    /**
     * Envia agora as notificações recém-criadas (MAIL_DISPATCH=inline).
     *
     * @param list<ObjectId> $ids
     * @return array<string, string> id => status depois do envio
     */
    private function dispatchCreated(array $ids): array
    {
        $statuses = [];
        if (!self::dispatchesInline()) {
            return $statuses;
        }
        foreach ($ids as $id) {
            $reserved = $this->notifications->reserveById($id, Bson::now(), self::staleBefore());
            if ($reserved === null) {
                continue;
            }
            $status = $this->deliver($reserved);
            if ($status !== null) {
                $statuses[(string)$id] = $status;
            }
        }
        return $statuses;
    }

    /**
     * Documento novo (PENDING). O hash gravado agora é de um token descartável que ninguém recebe: o link real é
     * gerado em cada tentativa de envio.
     *
     * @return array<string, mixed>
     */
    private function newDocument(
        ObjectId $attendanceId,
        ObjectId $studentId,
        ObjectId $guardianId,
        string $recipient,
        string $subject,
        string $body,
        ?ObjectId $resendOf,
        ?ObjectId $createdBy,
    ): array {
        $now = Bson::now();
        return [
            '_id' => new ObjectId(),
            'attendance_id' => $attendanceId,
            'student_id' => $studentId,
            'guardian_id' => $guardianId,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => NotificationStatus::PENDING->value,
            'attempts' => 0,
            'provider_message_id' => null,
            'justification_link' => [
                'token_hash' => hash('sha256', self::newToken()),
                'expires_at' => new UTCDateTime(Clock::now()->add(new DateInterval('P' . self::ttlDays() . 'D'))),
                'used_at' => null,
            ],
            'events' => [],
            'resend_of' => $resendOf,
            'locked_at' => null,
            'next_attempt_at' => null,
            'last_error' => null,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private static function staleBefore(): UTCDateTime
    {
        return new UTCDateTime(Clock::now()->sub(new DateInterval('PT' . self::LOCK_MINUTES . 'M')));
    }

    private static function parseInstant(string $value): ?UTCDateTime
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+\-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return new UTCDateTime(new DateTimeImmutable($value, new DateTimeZone('UTC')));
        } catch (Exception) {
            return null;
        }
    }
}
