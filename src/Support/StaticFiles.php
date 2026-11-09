<?php
declare(strict_types=1);

namespace Saa\Support;

/**
 * Decide se uma requisição aponta para um arquivo estático servível dentro de public/ (usado por router.php,
 * o roteador do servidor embutido do PHP). Recusa path traversal, barra invertida, ":" (fluxos alternativos do
 * Windows), segmentos ocultos (.env, .htaccess), links simbólicos para fora de public/ e qualquer arquivo
 * executável (.php, .phtml, .phar...). O que não for servido aqui segue para o front controller.
 */
final class StaticFiles
{
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'inc', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht'];

    private const MIME_TYPES = [
        'css' => 'text/css; charset=UTF-8',
        'js' => 'text/javascript; charset=UTF-8',
        'mjs' => 'text/javascript; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'map' => 'application/json; charset=UTF-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'txt' => 'text/plain; charset=UTF-8',
        'pdf' => 'application/pdf',
    ];

    /** Caminho real do arquivo se for servível; null caso contrário. */
    public static function resolve(string $publicDirectory, string $requestUri): ?string
    {
        $root = realpath($publicDirectory);
        if ($root === false) {
            return null;
        }
        $path = rawurldecode(substr($requestUri, 0, strcspn($requestUri, '?#')));
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':')) {
            return null;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || ($segment !== '' && $segment[0] === '.')) {
                return null;
            }
        }
        $real = realpath($root . $path);
        if ($real === false || !is_file($real) || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (in_array(strtolower(pathinfo($real, PATHINFO_EXTENSION)), self::BLOCKED_EXTENSIONS, true)) {
            return null;
        }
        return $real;
    }

    /** Content-Type pela extensão (desconhecidas: application/octet-stream). */
    public static function mimeType(string $file): string
    {
        return self::MIME_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
