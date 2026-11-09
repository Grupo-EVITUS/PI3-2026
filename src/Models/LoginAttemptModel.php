<?php
declare(strict_types=1);

namespace Saa\Models;

use DateTimeImmutable;
use DateTimeInterface;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Bson;
use Saa\Database\Collections;

/**
 * Coleção login_attempts: uma linha por tentativa de login FALHA {key, at}. A chave é o SHA-256 de
 * "email normalizado|ip" (nem o e-mail digitado nem o IP ficam guardados). O Schema cria um índice TTL em "at",
 * então as linhas antigas somem sozinhas; o limite usa apenas a janela (LOGIN_WINDOW_MINUTES).
 */
final class LoginAttemptModel extends Model
{
    protected const COLLECTION = Collections::LOGIN_ATTEMPTS;

    public static function keyFor(string $emailNormalized, string $ip): string
    {
        return hash('sha256', $emailNormalized . '|' . $ip);
    }

    public function record(string $key): void
    {
        $this->collection()->insertOne(['key' => $key, 'at' => Bson::now()]);
    }

    /** Quantas falhas desta chave ocorreram desde $since. */
    public function countSince(string $key, DateTimeInterface $since): int
    {
        return $this->collection()->countDocuments(['key' => $key, 'at' => ['$gte' => new UTCDateTime($since)]]);
    }

    /** Instante da falha mais antiga ainda dentro da janela (para calcular Retry-After). */
    public function oldestSince(string $key, DateTimeInterface $since): ?DateTimeImmutable
    {
        $attempt = $this->collection()->findOne(
            ['key' => $key, 'at' => ['$gte' => new UTCDateTime($since)]],
            ['sort' => ['at' => 1], 'projection' => ['at' => 1]]
        );
        return is_array($attempt) ? Bson::dateTime($attempt['at'] ?? null) : null;
    }

    /** Apaga as falhas da chave (após um login bem-sucedido). */
    public function clear(string $key): void
    {
        $this->collection()->deleteMany(['key' => $key]);
    }
}
