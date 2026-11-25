<?php
declare(strict_types=1);

namespace Saa\Database;

use DomainException;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use MongoDB\Operation\FindOneAndUpdate;
use Throwable;
use function MongoDB\with_transaction;

/**
 * Recuperação de senha da equipe (AOE e Coordenação) por link enviado ao e-mail cadastrado.
 *
 * - O pedido nunca revela se o e-mail existe: a resposta é sempre a mesma e leva um tempo mínimo.
 * - O token tem 256 bits, vale 1 hora, é de uso único e só o hash fica no banco.
 * - Um novo pedido revoga os anteriores; no máximo um pedido a cada 5 minutos por conta.
 * - O e-mail é entregue pelo worker (fila no próprio documento), nunca na requisição web.
 */
final class MongoPasswordReset
{
    /** Perfis que podem recuperar a senha por conta própria. */
    public const PROFILES = ['AOE', 'COORDENACAO'];
    public const TTL_SECONDS = 3600;
    public const MIN_INTERVAL_SECONDS = 300;
    public const MIN_PASSWORD_BYTES = 10;
    public const MAX_PASSWORD_BYTES = 72;
    private const MIN_REQUEST_MICROSECONDS = 300000;
    private const MAX_EMAIL_BYTES = 254;
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Database $database)
    {
    }

    public static function isToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    /** Registra o pedido, se cabível, e não informa ao chamador se havia conta. */
    public function request(mixed $email, string $baseUrl): void
    {
        $baseUrl = PublicUrl::normalize($baseUrl);
        $started = hrtime(true);
        try {
            if (is_string($email) && strlen($email) <= self::MAX_EMAIL_BYTES) {
                $email = trim($email);
                if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $this->create($email, $baseUrl);
                }
            }
        } finally {
            // Iguala o tempo de resposta de conta existente e inexistente.
            $remaining = self::MIN_REQUEST_MICROSECONDS - (int)((hrtime(true) - $started) / 1000);
            if ($remaining > 0) {
                usleep($remaining);
            }
        }
    }

    private function create(string $email, string $baseUrl): void
    {
        $user = $this->database->users->findOne([
            'email_key' => Schema::emailKey($email), 'active' => true, 'profile' => ['$in' => self::PROFILES],
        ], ['projection' => ['_id' => 1, 'name' => 1, 'email' => 1]]);
        if ($user === null) {
            return;
        }
        $recent = $this->database->password_resets->countDocuments([
            'user_id' => $user['_id'],
            'created_at' => ['$gt' => new UTCDateTime((time() - self::MIN_INTERVAL_SECONDS) * 1000)],
        ]);
        if ($recent > 0) {
            return;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $link = $baseUrl . '/redefinir-senha/' . $token;
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($user, $token, $link): void {
                $options = ['session' => $session];
                $now = new UTCDateTime();
                $this->revokePending($user['_id'], null, $now, $options);
                $this->database->password_resets->insertOne([
                    '_id' => new ObjectId(), 'user_id' => $user['_id'], 'token_hash' => hash('sha256', $token),
                    'created_at' => $now,
                    'expires_at' => new UTCDateTime((time() + self::TTL_SECONDS) * 1000),
                    'mail_status' => 'AGUARDANDO',
                    'mail' => [
                        'recipient' => $user['email'],
                        'subject' => 'Redefinição de senha — Presença',
                        'body' => 'Olá, ' . $user['name'] . ".\n\n"
                            . "Recebemos um pedido para redefinir a senha da sua conta no sistema de ausências.\n"
                            . "Para criar uma nova senha, acesse o link abaixo (vale por 1 hora e só pode ser usado uma vez):\n\n"
                            . $link
                            . "\n\nSe você não fez esse pedido, ignore este e-mail: sua senha continua a mesma."
                            . "\n\nAtenciosamente,\nEquipe escolar",
                    ],
                ], $options);
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    null, 'SENHA_REDEFINICAO_SOLICITADA', 'usuario', $user['_id'], '', $session
                );
            });
        } finally {
            $session->endSession();
        }
    }

    /** @return array{id: ObjectId, name: string}|null o pedido válido e o nome da conta */
    public function find(string $token): ?array
    {
        return self::isToken($token) ? $this->lookup($token) : null;
    }

    /**
     * @throws InvalidArgumentException senha fora da política
     * @throws DomainException link inválido, expirado, já usado ou conta sem acesso
     */
    public function reset(string $token, mixed $password): void
    {
        if (!is_string($password) || strlen($password) < self::MIN_PASSWORD_BYTES
            || strlen($password) > self::MAX_PASSWORD_BYTES || str_contains($password, "\0")) {
            throw new InvalidArgumentException(
                'A senha deve ter de ' . self::MIN_PASSWORD_BYTES . ' a ' . self::MAX_PASSWORD_BYTES . ' caracteres.'
            );
        }
        if (!self::isToken($token)) {
            throw new DomainException('Este link é inválido ou expirou.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($token, $hash): void {
                $options = ['session' => $session];
                $now = new UTCDateTime();
                $reset = $this->lookup($token, $session);
                if ($reset === null) {
                    throw new DomainException('Este link é inválido ou expirou.');
                }
                // Reivindicação atômica: duas requisições com o mesmo link não passam as duas.
                if ($this->database->password_resets->updateOne(
                    ['_id' => $reset['id'], 'used_at' => null, 'revoked_at' => null,
                     'expires_at' => ['$gt' => $now]],
                    ['$set' => ['used_at' => $now, 'mail.body' => '']], $options
                )->getMatchedCount() !== 1) {
                    throw new DomainException('Este link é inválido ou expirou.');
                }
                if ($this->database->users->updateOne(
                    ['_id' => $reset['user_id'], 'active' => true, 'profile' => ['$in' => self::PROFILES]],
                    ['$set' => ['password_hash' => $hash]], $options
                )->getMatchedCount() !== 1) {
                    throw new DomainException('Este link é inválido ou expirou.');
                }
                $this->revokePending($reset['user_id'], $reset['id'], $now, $options);
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    $reset['user_id'], 'SENHA_REDEFINIDA', 'usuario', $reset['user_id'], '', $session
                );
            });
        } finally {
            $session->endSession();
        }
    }

    /**
     * Entrega os e-mails de redefinição pendentes (reserva com lease, até 3 tentativas).
     * Pedidos já usados, revogados ou expirados não são enviados.
     */
    public function deliver(callable $send, int $limit = 20): int
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limite de processamento inválido.');
        }
        $processed = 0;
        for ($i = 0; $i < $limit; $i++) {
            $now = new UTCDateTime();
            $claimId = new ObjectId();
            $job = $this->database->password_resets->findOneAndUpdate(
                ['used_at' => null, 'revoked_at' => null, 'expires_at' => ['$gt' => $now], '$or' => [
                    ['mail_status' => 'AGUARDANDO', '$or' => [
                        ['next_attempt_at' => null], ['next_attempt_at' => ['$lte' => $now]],
                    ]],
                    ['mail_status' => 'PROCESSANDO', 'started_at' => ['$lt' => new UTCDateTime((time() - 300) * 1000)]],
                ]],
                ['$set' => ['mail_status' => 'PROCESSANDO', 'started_at' => $now, 'claim_id' => $claimId],
                 '$inc' => ['attempts' => 1]],
                ['sort' => ['created_at' => 1, '_id' => 1],
                 'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER]
            );
            if ($job === null) {
                break;
            }
            $success = true;
            try {
                $send(['recipient' => $job['mail']['recipient'], 'subject' => $job['mail']['subject'],
                    'body' => $job['mail']['body']]);
            } catch (Throwable) {
                $success = false;
            }
            $attempts = (int)$job['attempts'];
            $terminal = !$success && $attempts >= self::MAX_ATTEMPTS;
            $status = $success ? 'CONCLUIDO' : ($terminal ? 'FALHA' : 'AGUARDANDO');
            $fields = [
                'mail_status' => $status, 'started_at' => null, 'processed_at' => $status === 'AGUARDANDO' ? null : new UTCDateTime(),
                'next_attempt_at' => $status === 'AGUARDANDO'
                    ? new UTCDateTime((time() + 60 * (2 ** ($attempts - 1))) * 1000) : null,
            ];
            if ($status !== 'AGUARDANDO') {
                $fields['mail.body'] = '';
            }
            $updated = $this->database->password_resets->updateOne(
                ['_id' => $job['_id'], 'mail_status' => 'PROCESSANDO', 'claim_id' => $claimId],
                ['$set' => $fields, '$unset' => ['claim_id' => '']]
            );
            if ($status !== 'AGUARDANDO' && $updated->getMatchedCount() === 1) {
                (new MongoOperationLogWriter($this->database->operation_logs))->append(
                    null, $success ? 'SENHA_EMAIL_ENVIADO' : 'SENHA_EMAIL_FALHA', 'usuario', $job['user_id']
                );
            }
            $processed++;
        }
        return $processed;
    }

    private function lookup(string $token, ?Session $session = null): ?array
    {
        $options = $session === null ? [] : ['session' => $session];
        $reset = $this->database->password_resets->findOne([
            'token_hash' => hash('sha256', $token), 'used_at' => null, 'revoked_at' => null,
            'expires_at' => ['$gt' => new UTCDateTime()],
        ], $options);
        if ($reset === null) {
            return null;
        }
        $user = $this->database->users->findOne(
            ['_id' => $reset['user_id'], 'active' => true, 'profile' => ['$in' => self::PROFILES]],
            ['projection' => ['name' => 1]] + $options
        );
        return $user === null ? null : ['id' => $reset['_id'], 'user_id' => $reset['user_id'], 'name' => $user['name']];
    }

    /** Invalida pedidos ainda abertos da conta (e apaga o link guardado no e-mail pendente). */
    private function revokePending(ObjectId $userId, ?ObjectId $except, UTCDateTime $now, array $options): void
    {
        $filter = ['user_id' => $userId, 'used_at' => null, 'revoked_at' => null];
        if ($except !== null) {
            $filter['_id'] = ['$ne' => $except];
        }
        $this->database->password_resets->updateMany(
            $filter, ['$set' => ['revoked_at' => $now, 'mail.body' => '']], $options
        );
    }
}
