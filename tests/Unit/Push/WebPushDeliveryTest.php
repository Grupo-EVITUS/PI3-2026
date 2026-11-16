<?php
declare(strict_types=1);

use Saa\Push\WebPush;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/PushTestSupport.php';

// Entrega HTTP real a um serviço de push local: cabeçalhos, cifra decifrável e VAPID verificável.
function deliveryCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('WebPushDeliveryTest: ' . $message);
    }
}

$stubDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa-push-stub-' . bin2hex(random_bytes(6));
mkdir($stubDir);
$port = 20000 + random_int(0, 20000);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__, 2) . '/Integration/push_service_stub.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $stubDir . '/server.log', 'a'], 2 => ['file', $stubDir . '/server.log', 'a']],
    $pipes, null, ['PUSH_STUB_DIR' => $stubDir] + getenv()
);
try {
    for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
        usleep(100_000);
    }
    putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES=http://127.0.0.1:' . $port . '/push/');
    $vapid = WebPush::generateKeyPair();
    $pusher = new WebPush($vapid['public'], $vapid['private'], 'mailto:escola@example.org', 3600);
    $device = WebPush::generateKeyPair();
    $auth = random_bytes(16);
    $subscription = WebPush::parseSubscription([
        'endpoint' => 'http://127.0.0.1:' . $port . '/push/aparelho-1',
        'keys' => ['p256dh' => WebPush::base64UrlEncode($device['public']), 'auth' => WebPush::base64UrlEncode($auth)],
    ]);
    $payload = '{"title":"Ausência de Ana","url":"/justificar/abc"}';
    deliveryCheck($pusher->send($subscription, $payload, 'abc123') === 201, 'status de aceite inesperado.');
    $gone = ['endpoint' => 'http://127.0.0.1:' . $port . '/push/gone-1'] + $subscription;
    deliveryCheck($pusher->send($gone, $payload) === 410, 'inscrição extinta não devolveu 410.');

    $files = glob($stubDir . '/aparelho-1-*.json');
    deliveryCheck(count($files) === 1, 'serviço de push não recebeu a mensagem.');
    $record = json_decode((string)file_get_contents($files[0]), true);
    $headers = $record['headers'];
    deliveryCheck(($headers['content-encoding'] ?? '') === 'aes128gcm' && ($headers['ttl'] ?? '') === '3600'
        && ($headers['urgency'] ?? '') === 'high' && ($headers['topic'] ?? '') === 'abc123', 'cabeçalhos Web Push ausentes.');
    $vapidClaims = PushTestSupport::verifyVapid($headers['authorization'] ?? '');
    deliveryCheck($vapidClaims !== null && $vapidClaims['claims']['aud'] === 'http://127.0.0.1:' . $port
        && $vapidClaims['k'] === $vapid['public'], 'VAPID inválido na entrega.');
    deliveryCheck(PushTestSupport::decrypt(base64_decode($record['body']), $device, $auth) === $payload,
        'o aparelho não decifra o conteúdo recebido.');
    fwrite(STDOUT, "WebPushDeliveryTest: entrega HTTP, cabeçalhos, VAPID e decifragem no aparelho passaram.\n");
} finally {
    proc_terminate($server);
    proc_close($server);
    putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES');
    array_map('unlink', glob($stubDir . '/*') ?: []);
    @rmdir($stubDir);
}
