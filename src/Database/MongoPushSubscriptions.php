<?php
declare(strict_types=1);

namespace Saa\Database;

use DomainException;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use RuntimeException;
use Saa\Push\WebPush;
use function MongoDB\with_transaction;

/**
 * Aparelhos que recebem notificação push de um responsável. O responsável não tem login:
 * a inscrição exige um convite de uso único gerado pela coordenação ou o link de um comunicado.
 */
final class MongoPushSubscriptions
{
    public const MAX_ACTIVE_PER_GUARDIAN = 5;
    private const INVITE_DAYS = 7;

    public function __construct(private readonly Database $database)
    {
    }

    /** Gera convite de ativação; o token só existe na resposta, o banco guarda o hash. */
    public function createInvite(ObjectId $actor, ObjectId $guardianId): string
    {
        $token = self::newToken();
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($actor, $guardianId, $token): void {
                $options = ['session' => $session];
                if ($this->database->users->countDocuments(
                    ['_id' => $actor, 'active' => true, 'profile' => 'COORDENACAO'], $options
                ) !== 1) {
                    throw new RuntimeException('Convite permitido somente à coordenação ativa.');
                }
                if ($this->database->guardians->countDocuments(['_id' => $guardianId, 'active' => true], $options) !== 1) {
                    throw new InvalidArgumentException('Responsável não encontrado ou arquivado.');
                }
                $now = time();
                $inviteId = new ObjectId();
                $this->database->push_invites->insertOne([
                    '_id' => $inviteId, 'guardian_id' => $guardianId, 'token_hash' => hash('sha256', $token),
                    'created_by' => $actor, 'created_at' => new UTCDateTime($now * 1000),
                    'expires_at' => new UTCDateTime(($now + self::INVITE_DAYS * 86400) * 1000), 'used_at' => null,
                ], $options);
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    $actor, 'CONVITE_PUSH_GERADO', 'responsavel', $guardianId, 'Válido por ' . self::INVITE_DAYS . ' dias', $session
                );
            });
        } finally {
            $session->endSession();
        }
        return $token;
    }

    /** @return array{guardian_id: ObjectId, guardian_name: string}|null */
    public function findInvite(string $token, ?Session $session = null): ?array
    {
        if (!self::isToken($token)) {
            return null;
        }
        $options = $session === null ? [] : ['session' => $session];
        $invite = $this->database->push_invites->findOne([
            'token_hash' => hash('sha256', $token), 'used_at' => null,
            'expires_at' => ['$gt' => new UTCDateTime()],
        ], $options);
        if ($invite === null) {
            return null;
        }
        $guardian = $this->database->guardians->findOne(['_id' => $invite['guardian_id'], 'active' => true], $options);
        return $guardian === null ? null
            : ['guardian_id' => $guardian['_id'], 'guardian_name' => (string)$guardian['name']];
    }

    /** Consome o convite e inscreve o aparelho na mesma transação. */
    public function subscribeWithInvite(string $token, mixed $subscription): void
    {
        $parsed = WebPush::parseSubscription($subscription);
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($token, $parsed): void {
                $invite = $this->findInvite($token, $session);
                if ($invite === null) {
                    throw new DomainException('Este convite é inválido, expirou ou já foi usado.');
                }
                $consumed = $this->database->push_invites->updateOne(
                    ['token_hash' => hash('sha256', $token), 'used_at' => null],
                    ['$set' => ['used_at' => new UTCDateTime()]], ['session' => $session]
                );
                if ($consumed->getModifiedCount() !== 1) {
                    throw new DomainException('Este convite é inválido, expirou ou já foi usado.');
                }
                $this->store($invite['guardian_id'], $parsed, 'CONVITE', $session);
            });
        } finally {
            $session->endSession();
        }
    }

    /** O link do comunicado já identifica o responsável; reaproveitá-lo para ativar o push no mesmo aparelho. */
    public function subscribeWithCommunication(string $token, mixed $subscription): void
    {
        $parsed = WebPush::parseSubscription($subscription);
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($token, $parsed): void {
                $found = (new MongoJustification($this->database))->find($token);
                if ($found === null || $this->database->guardians->countDocuments(
                    ['_id' => $found['guardian_id'], 'active' => true], ['session' => $session]
                ) !== 1) {
                    throw new DomainException('Este link é inválido ou não está mais disponível.');
                }
                $this->store($found['guardian_id'], $parsed, 'COMUNICADO', $session);
            });
        } finally {
            $session->endSession();
        }
    }

    public function activeCount(ObjectId $guardianId): int
    {
        return $this->database->push_subscriptions->countDocuments(['guardian_id' => $guardianId, 'active' => true]);
    }

    /** @return array<string, int> contagem de aparelhos ativos por responsável */
    public function activeCounts(): array
    {
        $counts = [];
        foreach ($this->database->push_subscriptions->aggregate([
            ['$match' => ['active' => true]],
            ['$group' => ['_id' => '$guardian_id', 'total' => ['$sum' => 1]]],
        ]) as $row) {
            $counts[(string)$row['_id']] = (int)$row['total'];
        }
        return $counts;
    }

    public static function isToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /** @param array{endpoint: string, p256dh: string, auth: string} $parsed */
    private function store(ObjectId $guardianId, array $parsed, string $source, Session $session): void
    {
        $options = ['session' => $session];
        $now = new UTCDateTime();
        $endpointHash = hash('sha256', $parsed['endpoint']);
        // Reinscrição do mesmo aparelho renova as chaves e reativa o registro.
        $this->database->push_subscriptions->updateOne(
            ['guardian_id' => $guardianId, 'endpoint_hash' => $endpointHash],
            ['$set' => ['endpoint' => $parsed['endpoint'], 'p256dh' => $parsed['p256dh'], 'auth' => $parsed['auth'],
                'active' => true, 'created_at' => $now, 'source' => $source,
                'revoked_at' => null, 'revoke_reason' => null],
             '$setOnInsert' => ['last_success_at' => null]],
            ['upsert' => true, 'session' => $session]
        );
        $active = $this->database->push_subscriptions->find(
            ['guardian_id' => $guardianId, 'active' => true],
            ['sort' => ['created_at' => -1, '_id' => -1], 'projection' => ['_id' => 1], 'session' => $session]
        )->toArray();
        $excess = array_map(static fn($row) => $row['_id'], array_slice($active, self::MAX_ACTIVE_PER_GUARDIAN));
        if ($excess !== []) {
            $this->database->push_subscriptions->updateMany(
                ['_id' => ['$in' => $excess]],
                ['$set' => ['active' => false, 'revoked_at' => $now, 'revoke_reason' => 'LIMITE_APARELHOS']],
                $options
            );
        }
        (new MongoOperationLogWriter($this->database->operation_logs))->append(
            null, 'PUSH_INSCRITO', 'responsavel', $guardianId, 'Origem ' . $source, $session
        );
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
