<?php
declare(strict_types=1);

namespace Saa\Support;

use Saa\Core\Config;
use SensitiveParameter;

/**
 * Senhas com password_hash(PASSWORD_DEFAULT). O custo vem de PASSWORD_COST (padrão: o do PHP; mínimo 10 em
 * produção e 4 nos demais ambientes — testes usam 4 para ficarem rápidos). Hashes antigos são atualizados no
 * próximo login (password_needs_rehash).
 */
final class PasswordHasher
{
    /**
     * Hashes válidos de senhas aleatórias descartadas, um por custo. Usados quando o e-mail não existe, para que
     * password_verify() gaste o mesmo tempo e a resposta não revele se o usuário existe.
     */
    private const DUMMY_HASHES = [
        4 => '$2y$04$u.YwuIrg9/dYY.xz.SsyzOmnX4laP.Hj.vXr4icUJOk2SlZF4DmWu',
        10 => '$2y$10$wsWojGn5ySmdZ5yEEd.jm.aplHxIV.I7hTH4I8A00Y5GLS5scpZSO',
        11 => '$2y$11$6Eqp.VWuEsbAIMXwyF4Vo.ho9lEG54qDvZl5fzGW4ikHkUaLdDxJe',
        12 => '$2y$12$KfiF1gu4qUpBEXbT8TTL1evI64pVDHoi/RCrpJ1kRznuyDJIBPD8i',
        13 => '$2y$13$cgxuO5SYviBj6Pi7fiL2Hu.6RtSOuBehAnJowNrEiV17JlsGFnIwO',
        14 => '$2y$14$EGZBwK2HkIiXGDLYX1CQIua9bsnw.jyik6D15WEpfkXvtZJDpkNqO',
    ];

    public static function cost(): int
    {
        $default = defined('PASSWORD_BCRYPT_DEFAULT_COST') ? PASSWORD_BCRYPT_DEFAULT_COST : 10;
        $minimum = Config::isProduction() ? 10 : 4;
        return min(15, max($minimum, Config::int('PASSWORD_COST', $default)));
    }

    public static function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, ['cost' => self::cost()]);
    }

    public static function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, ['cost' => self::cost()]);
    }

    /** Hash fictício com o custo atual (para verificar senha de usuário inexistente). */
    public static function dummyHash(): string
    {
        $cost = self::cost();
        return self::DUMMY_HASHES[$cost] ?? password_hash('saa-dummy-' . $cost, PASSWORD_DEFAULT, ['cost' => $cost]);
    }
}
