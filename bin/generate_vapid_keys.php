<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Saa\Push\WebPush;

// Gera o par VAPID que identifica o servidor da escola perante os serviços de push.
// Gere uma única vez por ambiente: trocar a chave invalida as inscrições dos aparelhos.
$pair = WebPush::generateKeyPair();
echo 'VAPID_PUBLIC_KEY=' . WebPush::base64UrlEncode($pair['public']) . PHP_EOL;
echo 'VAPID_PRIVATE_KEY=' . WebPush::base64UrlEncode($pair['private']) . PHP_EOL;
echo 'VAPID_SUBJECT=mailto:secretaria@escola.example.org' . PHP_EOL;
fwrite(STDERR, "Guarde VAPID_PRIVATE_KEY como segredo; não versione este valor.\n");
