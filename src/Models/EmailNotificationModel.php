<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Schema;
use Saa\Domain\NotificationEventType;
use Saa\Domain\NotificationStatus;

/**
 * Coleção email_notifications: um e-mail de ausência por responsável (reenvio = nova notificação com resend_of).
 *
 * - justification_link guarda só o SHA-256 do token; leituras NUNCA devolvem token_hash (projeção);
 * - fila: reserve*() usa findOneAndUpdate com locked_at (reserva expirada pode ser retomada), então dois
 *   processadores nunca pegam a mesma mensagem;
 * - events é limitado a Schema::MAX_NOTIFICATION_EVENTS com $push + $slice (mantém os mais recentes).
 */
final class EmailNotificationModel extends Model
{
    protected const COLLECTION = Collections::EMAIL_NOTIFICATIONS;
    private const PROJECTION = ['justification_link.token_hash' => 0];

    /**
     * @param array<string, mixed> $document documento completo montado pelo serviço
     */
    public function insert(array $document): ObjectId
    {
        $document['_id'] ??= new ObjectId();
        $this->collection()->insertOne($document);
        return $document['_id'];
    }

    /** @return array<string, mixed>|null sem token_hash */
    public function findById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $notification = $this->collection()->findOne(['_id' => $oid], ['projection' => self::PROJECTION]);
        return is_array($notification) ? $notification : null;
    }

    /** @return array<string, mixed>|null sem token_hash */
    public function findByProviderMessageId(string $providerMessageId): ?array
    {
        if ($providerMessageId === '') {
            return null;
        }
        $notification = $this->collection()->findOne(['provider_message_id' => $providerMessageId], ['projection' => self::PROJECTION]);
        return is_array($notification) ? $notification : null;
    }

    /** @return list<array<string, mixed>> comunicações de uma ausência, mais antigas primeiro (sem token_hash) */
    public function findByAttendance(ObjectId $attendanceId): array
    {
        return array_values($this->collection()->find(
            ['attendance_id' => $attendanceId],
            ['projection' => self::PROJECTION, 'sort' => ['created_at' => 1, '_id' => 1]]
        )->toArray());
    }

    // ------------------------------------------------------------------ fila

    /**
     * Reserva a próxima mensagem pronta para envio: PENDING (sem espera ou com espera vencida) ou FAILED com
     * attempts < 3 e next_attempt_at vencido; sem reserva ativa (locked_at nulo ou anterior a $staleBefore).
     *
     * @return array<string, mixed>|null documento já reservado (locked_at = $now), sem token_hash
     */
    public function reserveNext(UTCDateTime $now, UTCDateTime $staleBefore): ?array
    {
        return $this->reserve(self::readyFilter($now, $staleBefore), $now);
    }

    /** Reserva uma mensagem específica (envio imediato após o registro), se ela estiver pronta. @return array<string, mixed>|null */
    public function reserveById(ObjectId $id, UTCDateTime $now, UTCDateTime $staleBefore): ?array
    {
        return $this->reserve(['_id' => $id] + self::readyFilter($now, $staleBefore), $now);
    }

    /**
     * Grava o hash do token gerado para ESTA tentativa (o token em claro só existe no e-mail enviado).
     * Exige a reserva ($lockedAt): quem perdeu a reserva não troca o link.
     */
    public function replaceJustificationLink(ObjectId $id, UTCDateTime $lockedAt, string $tokenHash, UTCDateTime $expiresAt): bool
    {
        $result = $this->collection()->updateOne(
            ['_id' => $id, 'locked_at' => $lockedAt],
            ['$set' => [
                'justification_link' => ['token_hash' => $tokenHash, 'expires_at' => $expiresAt, 'used_at' => null],
                'updated_at' => Bson::now(),
            ]]
        );
        return $result->getMatchedCount() === 1;
    }

    /** Envio aceito: SENT, attempts + 1, id do provedor, evento SENT; libera a reserva. */
    public function markSent(ObjectId $id, ?string $providerMessageId, UTCDateTime $now): void
    {
        $this->collection()->updateOne(['_id' => $id], [
            '$set' => [
                'status' => NotificationStatus::SENT->value,
                'provider_message_id' => $providerMessageId !== null && $providerMessageId !== '' ? mb_substr($providerMessageId, 0, 255) : null,
                'locked_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'updated_at' => $now,
            ],
            '$inc' => ['attempts' => 1],
            '$push' => ['events' => self::eachEvent(NotificationEventType::SENT->value, $now, null)],
        ]);
    }

    /**
     * Falha na tentativa: FAILED, attempts + 1, last_error (sem segredos), próxima tentativa (null = desistiu),
     * evento FAILED; libera a reserva.
     */
    public function markFailed(ObjectId $id, string $error, ?UTCDateTime $nextAttemptAt, UTCDateTime $now): void
    {
        $this->collection()->updateOne(['_id' => $id], [
            '$set' => [
                'status' => NotificationStatus::FAILED->value,
                'locked_at' => null,
                'next_attempt_at' => $nextAttemptAt,
                'last_error' => mb_substr($error, 0, 1000),
                'updated_at' => $now,
            ],
            '$inc' => ['attempts' => 1],
            '$push' => ['events' => self::eachEvent(NotificationEventType::FAILED->value, $now, mb_substr($error, 0, 500))],
        ]);
    }

    /** Libera a reserva sem contar tentativa (erro antes de tentar o envio). */
    public function release(ObjectId $id): void
    {
        $this->collection()->updateOne(['_id' => $id], ['$set' => ['locked_at' => null]]);
    }

    // ------------------------------------------------------------------ webhook do provedor

    /**
     * Acrescenta um evento do provedor e, quando $allowedFrom contém o status atual, muda o status para $newStatus
     * — tudo numa única escrita atômica (o status nunca regride, mesmo com webhooks simultâneos).
     *
     * @param list<string> $allowedFrom status atuais que podem ser substituídos por $newStatus
     * @return array<string, mixed>|null documento atualizado (sem token_hash); null se a notificação não existe
     */
    public function applyProviderEvent(
        ObjectId $id,
        string $eventType,
        string $newStatus,
        array $allowedFrom,
        UTCDateTime $occurredAt,
        ?string $detail,
        UTCDateTime $now,
    ): ?array {
        $promote = ['$in' => ['$status', array_values($allowedFrom)]];
        $set = [
            'status' => ['$cond' => [$promote, ['$literal' => $newStatus], '$status']],
            'events' => ['$slice' => [
                ['$concatArrays' => [
                    ['$ifNull' => ['$events', ['$literal' => []]]],
                    [['$literal' => self::event($eventType, $occurredAt, $detail)]],
                ]],
                -Schema::MAX_NOTIFICATION_EVENTS,
            ]],
            'updated_at' => $now,
        ];
        if ($eventType === NotificationEventType::FAILED->value && $detail !== null && $detail !== '') {
            $set['last_error'] = ['$cond' => [$promote, ['$literal' => mb_substr($detail, 0, 1000)], '$last_error']];
        }
        $notification = $this->collection()->findOneAndUpdate(
            ['_id' => $id],
            [['$set' => $set]],
            ['returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER, 'projection' => self::PROJECTION]
        );
        return is_array($notification) ? $notification : null;
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private static function readyFilter(UTCDateTime $now, UTCDateTime $staleBefore): array
    {
        return [
            '$and' => [
                ['$or' => [
                    ['status' => NotificationStatus::PENDING->value, 'next_attempt_at' => null],
                    ['status' => NotificationStatus::PENDING->value, 'next_attempt_at' => ['$lte' => $now]],
                    [
                        'status' => NotificationStatus::FAILED->value,
                        'attempts' => ['$lt' => Schema::MAX_NOTIFICATION_ATTEMPTS],
                        'next_attempt_at' => ['$lte' => $now],
                    ],
                ]],
                ['$or' => [['locked_at' => null], ['locked_at' => ['$lt' => $staleBefore]]]],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>|null
     */
    private function reserve(array $filter, UTCDateTime $now): ?array
    {
        $notification = $this->collection()->findOneAndUpdate(
            $filter,
            ['$set' => ['locked_at' => $now, 'updated_at' => $now]],
            [
                'sort' => ['created_at' => 1, '_id' => 1],
                'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                'projection' => self::PROJECTION,
            ]
        );
        return is_array($notification) ? $notification : null;
    }

    /** @return array<string, mixed> */
    private static function event(string $type, UTCDateTime $at, ?string $detail): array
    {
        return ['type' => $type, 'occurred_at' => $at, 'detail' => $detail === null ? null : mb_substr($detail, 0, 500)];
    }

    /** @return array<string, mixed> $push limitado aos eventos mais recentes */
    private static function eachEvent(string $type, UTCDateTime $at, ?string $detail): array
    {
        return ['$each' => [self::event($type, $at, $detail)], '$slice' => -Schema::MAX_NOTIFICATION_EVENTS];
    }
}
