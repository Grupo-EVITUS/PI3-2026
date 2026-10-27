<?php
$publicDirectory = realpath(__DIR__ . '/public');
$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$requested = realpath($publicDirectory . DIRECTORY_SEPARATOR . ltrim($requestPath, '/\\'));
if (
    $publicDirectory !== false
    && $requested !== false
    && str_starts_with($requested, $publicDirectory . DIRECTORY_SEPARATOR)
    && is_file($requested)
) {
    $mime = match (strtolower(pathinfo($requested, PATHINFO_EXTENSION))) {
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    readfile($requested);
    return true;
}
require __DIR__ . '/public/index.php';
