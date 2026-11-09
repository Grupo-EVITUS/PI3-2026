<?php
declare(strict_types=1);

namespace Saa\Support;

/**
 * Validação do parâmetro "next" (para onde voltar após o login). Só aceita caminho interno: começa com "/",
 * sem "//" inicial, sem barra invertida, sem esquema/host/usuário/porta e sem caracteres de controle ou quebras
 * de linha (injeção de cabeçalho). Qualquer outra coisa é descartada — evita redirecionamento aberto.
 */
final class SafeRedirect
{
    public const DEFAULT = '/home';
    private const MAX_LENGTH = 2000;

    /** O próprio valor se for um caminho interno seguro; null caso contrário. */
    public static function next(?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return null;
        }
        if ($candidate[0] !== '/' || str_starts_with($candidate, '//')) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $candidate) === 1) {
            return null;
        }
        $parts = parse_url($candidate);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return null;
        }
        $path = $parts['path'] ?? '';
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return null;
        }
        // não volta para as telas de entrada/saída (evita laço de redirecionamento)
        if ($path === '/login' || str_starts_with($path, '/login/') || $path === '/logout') {
            return null;
        }
        return $candidate;
    }

    /** Destino seguro: $candidate se válido, senão $fallback (padrão /home). */
    public static function nextOr(?string $candidate, string $fallback = self::DEFAULT): string
    {
        return self::next($candidate) ?? $fallback;
    }
}
