<?php
declare(strict_types=1);

namespace Saa\Core;

/**
 * Cabeçalhos de segurança aplicados pelo Kernel a TODA resposta dinâmica (docs/ARQUITETURA.md, seção 6).
 * Cabeçalhos já definidos pela resposta (ex.: um Cache-Control próprio) são preservados.
 */
final class SecurityHeaders
{
    public const CSP = "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'";
    public const PERMISSIONS_POLICY = 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()';
    public const HSTS = 'max-age=31536000';

    /** @return array<string, string> */
    public static function forRequest(Request $request): array
    {
        $headers = [
            'Content-Security-Policy' => self::CSP,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => self::PERMISSIONS_POLICY,
            // páginas dinâmicas são privadas (sessão, token CSRF, links de justificativa): nunca ficam em cache
            'Cache-Control' => 'no-store',
        ];
        if ($request->isSecure() || Config::isProduction()) {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }
        return $headers;
    }

    public static function apply(Response $response, Request $request): Response
    {
        foreach (self::forRequest($request) as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }
        return $response;
    }
}
