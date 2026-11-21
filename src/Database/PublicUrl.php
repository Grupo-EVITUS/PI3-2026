<?php
declare(strict_types=1);

namespace Saa\Database;

use InvalidArgumentException;

/** Valida o endereço público usado nos links enviados aos responsáveis. */
final class PublicUrl
{
    public static function normalize(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || strlen($baseUrl) > 300
            || preg_match('/[\x00-\x1f\x7f]/', $baseUrl) === 1) {
            throw new InvalidArgumentException('Endereço público do sistema inválido.');
        }
        return rtrim($baseUrl, '/');
    }
}
