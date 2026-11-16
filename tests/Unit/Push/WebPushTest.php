<?php
declare(strict_types=1);

use Saa\Push\WebPush;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/PushTestSupport.php';

function pushCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('WebPushTest: ' . $message);
    }
}

function pushRejects(callable $work, string $message): void
{
    try {
        $work();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException('WebPushTest: aceitou ' . $message);
}

$b = static fn(string $value): string => WebPush::base64UrlDecode($value) ?? throw new LogicException($value);

// RFC 8291 §5 e Apêndice A: o corpo cifrado é o cabeçalho do registro seguido do texto cifrado.
$rfcHeader = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
$rfcCipher = '8pfeW0KbunFT06SuDKoJH9Ql87S1QUrdirN6GcG7sFz1y1sqLgVi1VhjVkHsUoEsbI_0LpXMuGvnzQ';
$body = WebPush::encrypt(
    'When I grow up, I want to be a watermelon',
    $b('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
    $b('BTBZMqHH6r4Tts7J_aSIgg'),
    $b('DGv6ra1nlYgDCS1FRnbzlw'),
    ['private' => $b('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
     'public' => $b('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')]
);
pushCheck($body === $b($rfcHeader) . $b($rfcCipher), 'o corpo difere do vetor de teste da RFC 8291.');

// Ida e volta com chaves novas, decifrando como o navegador faria.
$userAgent = WebPush::generateKeyPair();
$auth = random_bytes(16);
$payload = json_encode(['title' => 'Ausência', 'body' => 'Olá, ção ✓'], JSON_THROW_ON_ERROR);
pushCheck(PushTestSupport::decrypt(WebPush::encrypt($payload, $userAgent['public'], $auth), $userAgent, $auth) === $payload,
    'ida e volta da cifra falhou.');
pushRejects(fn() => WebPush::encrypt(str_repeat('a', WebPush::MAX_PAYLOAD_BYTES + 1), $userAgent['public'], $auth),
    'conteúdo acima do limite');

// VAPID: JWT ES256 verificável com a chave pública e com audiência = origem do endpoint.
$vapid = WebPush::generateKeyPair();
$pusher = new WebPush($vapid['public'], $vapid['private'], 'mailto:escola@example.org');
$claims = PushTestSupport::verifyVapid(
    $pusher->vapidAuthorization('https://fcm.googleapis.com/fcm/send/abc', 1_800_000_000)
);
pushCheck($claims !== null && $claims['k'] === $vapid['public'], 'assinatura VAPID inválida.');
pushCheck($claims['claims'] === ['aud' => 'https://fcm.googleapis.com', 'exp' => 1_800_000_000 + 43200,
    'sub' => 'mailto:escola@example.org'], 'claims VAPID inesperadas.');
pushRejects(fn() => new WebPush($vapid['public'], WebPush::generateKeyPair()['private'], 'mailto:a@b.c'),
    'par VAPID trocado');
pushRejects(fn() => new WebPush($vapid['public'], $vapid['private'], 'escola'), 'subject VAPID inválido');

// Inscrição: só serviços de push conhecidos (evita SSRF) e chaves bem formadas.
putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES');
$keys = ['p256dh' => WebPush::base64UrlEncode($userAgent['public']), 'auth' => WebPush::base64UrlEncode($auth)];
$parsed = WebPush::parseSubscription(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x:y', 'keys' => $keys]);
pushCheck($parsed['p256dh'] === $keys['p256dh'] && $parsed['auth'] === $keys['auth'], 'inscrição válida alterada.');
WebPush::parseSubscription(['endpoint' => 'https://wns2-par02p.notify.windows.com/w/?token=x', 'keys' => $keys]);
foreach ([
    'http://fcm.googleapis.com/fcm/send/x', 'https://fcm.googleapis.com:8443/x', 'https://evil.example.org/x',
    'https://fcm.googleapis.com.evil.org/x', 'https://user@fcm.googleapis.com/x', 'https://127.0.0.1/x',
    "https://fcm.googleapis.com/x\n", 'file:///etc/passwd',
] as $endpoint) {
    pushRejects(fn() => WebPush::parseSubscription(['endpoint' => $endpoint, 'keys' => $keys]), $endpoint);
}
$badPoint = "\x04" . str_repeat("\x01", 64);
foreach ([
    ['p256dh' => $keys['p256dh'], 'auth' => 'AAAA'],
    ['p256dh' => WebPush::base64UrlEncode($badPoint), 'auth' => $keys['auth']],
    ['p256dh' => 'não-base64', 'auth' => $keys['auth']],
] as $index => $badKeys) {
    pushRejects(fn() => WebPush::parseSubscription(['endpoint' => 'https://fcm.googleapis.com/x', 'keys' => $badKeys]),
        'chaves inválidas #' . $index);
}
putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES=http://127.0.0.1:9/');
WebPush::parseSubscription(['endpoint' => 'http://127.0.0.1:9/push/1', 'keys' => $keys]);
pushRejects(fn() => WebPush::parseSubscription(['endpoint' => 'http://127.0.0.1:10/push/1', 'keys' => $keys]),
    'prefixo de teste não configurado');
putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES');

fwrite(STDOUT, "WebPushTest: vetor RFC 8291, ida e volta, VAPID e allowlist de endpoints passaram.\n");
