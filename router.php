<?php
declare(strict_types=1);

/**
 * Roteador do servidor embutido do PHP (desenvolvimento e testes):
 *
 *     php -S 127.0.0.1:8000 -t public router.php        (composer serve)
 *
 * Arquivo estático existente em public/ (sem path traversal, sem arquivos ocultos e sem .php) é servido como está;
 * todo o resto vai para o front controller (public/index.php). Em produção use Apache/Nginx apontando para public/.
 */

$publicDirectory = __DIR__ . '/public';

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';

    $file = Saa\Support\StaticFiles::resolve($publicDirectory, (string)($_SERVER['REQUEST_URI'] ?? '/'));
    if ($file !== null) {
        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== false && $documentRoot === realpath($publicDirectory)) {
            // DocumentRoot = public/: o servidor embutido entrega o arquivo com o Content-Type correto
            return false;
        }
        // sem "-t public" o servidor procuraria o arquivo na pasta errada; entregue-o daqui
        header('Content-Type: ' . Saa\Support\StaticFiles::mimeType($file));
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string)filesize($file));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            readfile($file);
        }
        return true;
    }
}

require $publicDirectory . '/index.php';
return true;
