<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\BSON\ObjectId;
use MongoDB\Collection;
use RuntimeException;

/**
 * Verifica credenciais no contrato MongoDB sem criar sessão nem trocar o runtime web.
 * A rota só deve usar este componente quando as operações ligadas à identidade também
 * tiverem sido portadas para MongoDB.
 */
final class MongoUserAuthenticator
{
    private const PROFILES = ['AOE', 'COORDENACAO', 'DIRETOR'];
    private const MAX_EMAIL_BYTES = 254;
    private const MAX_PASSWORD_BYTES = 4096;

    private static ?string $dummyPasswordHash = null;

    public function __construct(private readonly Collection $users)
    {
        // O custo base é pago na construção, não somente na primeira falha de login.
        self::dummyHash();
    }

    /** @return array{id: string, name: string, profile: string}|null */
    public function authenticate(mixed $email, mixed $password): ?array
    {
        if (!is_string($email) || !is_string($password)
            || strlen($email) > self::MAX_EMAIL_BYTES
            || strlen($password) > self::MAX_PASSWORD_BYTES
        ) {
            return null;
        }

        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $user = $this->users->findOne(
            ['email_key' => Schema::emailKey($email), 'active' => true],
            ['projection' => ['_id' => 1, 'name' => 1, 'password_hash' => 1, 'profile' => 1]]
        );

        if (!is_array($user)) {
            password_verify($password, self::dummyHash());
            password_verify($password, self::dummyHash());
            return null;
        }

        $hash = $user['password_hash'] ?? null;
        if (!is_string($hash)) {
            password_verify($password, self::dummyHash());
            password_verify($password, self::dummyHash());
            return null;
        }
        $hashInfo = password_get_info($hash);
        if (($hashInfo['algoName'] ?? 'unknown') === 'unknown') {
            password_verify($password, self::dummyHash());
            password_verify($password, self::dummyHash());
            return null;
        }
        if (!password_verify($password, $hash)) {
            password_verify($password, self::dummyHash());
            return null;
        }

        $id = $user['_id'] ?? null;
        $name = $user['name'] ?? null;
        $profile = $user['profile'] ?? null;
        if (!$id instanceof ObjectId || !is_string($name) || trim($name) === ''
            || !is_string($profile) || !in_array($profile, self::PROFILES, true)
        ) {
            return null;
        }

        return ['id' => (string)$id, 'name' => $name, 'profile' => $profile];
    }

    private static function dummyHash(): string
    {
        if (self::$dummyPasswordHash === null) {
            $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                throw new RuntimeException('Não foi possível preparar a verificação de credenciais.');
            }
            self::$dummyPasswordHash = $hash;
        }
        return self::$dummyPasswordHash;
    }
}
