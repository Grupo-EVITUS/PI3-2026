<?php
declare(strict_types=1);

namespace Saa\Push;

use InvalidArgumentException;
use RuntimeException;

/**
 * Web Push sem dependências extras: criptografia aes128gcm (RFC 8291) e
 * identificação VAPID (RFC 8292) usando apenas ext-openssl.
 */
final class WebPush
{
    private const SPKI_P256_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    private const RECORD_SIZE = 4096;
    public const MAX_PAYLOAD_BYTES = 3000;
    /** Serviços de push dos navegadores; o endpoint vem do aparelho e não pode virar SSRF. */
    private const DEFAULT_ALLOWED_HOSTS = [
        'fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com',
    ];
    private const DEFAULT_ALLOWED_SUFFIXES = ['.notify.windows.com', '.push.apple.com'];

    public function __construct(
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
        private readonly int $ttlSeconds = 86400,
        private readonly int $timeoutSeconds = 10,
    ) {
        if (strlen($vapidPublicKey) !== 65 || $vapidPublicKey[0] !== "\x04" || strlen($vapidPrivateKey) !== 32) {
            throw new InvalidArgumentException('Chaves VAPID inválidas.');
        }
        if (preg_match('#^(mailto:[^\s@]+@[^\s@]+|https://[^\s]+)$#', $vapidSubject) !== 1) {
            throw new InvalidArgumentException('VAPID_SUBJECT deve ser mailto:endereco ou https://site.');
        }
        // O OpenSSL aceita SEC1 com ponto público trocado; só a assinatura prova que o par é coerente.
        $probe = random_bytes(16);
        $signature = '';
        if (!openssl_sign($probe, $signature, self::privateKey($vapidPrivateKey, $vapidPublicKey), OPENSSL_ALGO_SHA256)
            || openssl_verify($probe, $signature, self::publicKey($vapidPublicKey), OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('VAPID_PUBLIC_KEY não corresponde a VAPID_PRIVATE_KEY.');
        }
    }

    public static function fromEnvironment(): self
    {
        $public = self::base64UrlDecode((string)getenv('VAPID_PUBLIC_KEY'));
        $private = self::base64UrlDecode((string)getenv('VAPID_PRIVATE_KEY'));
        if ($public === null || $private === null) {
            throw new RuntimeException('Configure VAPID_PUBLIC_KEY e VAPID_PRIVATE_KEY (php bin/generate_vapid_keys.php).');
        }
        $ttl = (int)(getenv('PUSH_TTL_SECONDS') ?: '86400');
        return new self($public, $private, (string)getenv('VAPID_SUBJECT'), max(60, min($ttl, 2419200)));
    }

    /** Chave pública em base64url para o applicationServerKey do navegador, ou null se o push não estiver configurado. */
    public static function configuredPublicKey(): ?string
    {
        $raw = self::base64UrlDecode((string)getenv('VAPID_PUBLIC_KEY'));
        return $raw !== null && strlen($raw) === 65 && $raw[0] === "\x04" ? self::base64UrlEncode($raw) : null;
    }

    /** @return array{public: string, private: string} chaves brutas (65 e 32 bytes) */
    public static function generateKeyPair(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) {
            throw new RuntimeException('OpenSSL não gerou a chave P-256.');
        }
        $ec = openssl_pkey_get_details($key)['ec'];
        return [
            'public' => "\x04" . self::pad32($ec['x']) . self::pad32($ec['y']),
            'private' => self::pad32($ec['d']),
        ];
    }

    /**
     * Valida a inscrição enviada pelo navegador (PushSubscription.toJSON()).
     * @return array{endpoint: string, p256dh: string, auth: string} chaves em base64url
     */
    public static function parseSubscription(mixed $data): array
    {
        if (!is_array($data) || !is_string($data['endpoint'] ?? null) || !is_array($data['keys'] ?? null)) {
            throw new InvalidArgumentException('Inscrição de notificação inválida.');
        }
        $endpoint = $data['endpoint'];
        self::assertAllowedEndpoint($endpoint);
        $p256dh = is_string($data['keys']['p256dh'] ?? null) ? self::base64UrlDecode($data['keys']['p256dh']) : null;
        $auth = is_string($data['keys']['auth'] ?? null) ? self::base64UrlDecode($data['keys']['auth']) : null;
        if ($p256dh === null || strlen($p256dh) !== 65 || $p256dh[0] !== "\x04"
            || $auth === null || strlen($auth) !== 16) {
            throw new InvalidArgumentException('Chaves da inscrição de notificação inválidas.');
        }
        self::publicKey($p256dh);
        return ['endpoint' => $endpoint, 'p256dh' => self::base64UrlEncode($p256dh),
            'auth' => self::base64UrlEncode($auth)];
    }

    public static function assertAllowedEndpoint(string $endpoint): void
    {
        if (strlen($endpoint) > 1024 || preg_match('/[\x00-\x20\x7f]/', $endpoint) === 1) {
            throw new InvalidArgumentException('Endereço de notificação inválido.');
        }
        foreach (array_filter(array_map('trim', explode(',', (string)getenv('PUSH_ALLOWED_ENDPOINT_PREFIXES')))) as $prefix) {
            // Somente para ambientes de teste com serviço de push local.
            if (str_starts_with($endpoint, $prefix)) {
                return;
            }
        }
        $parts = parse_url($endpoint);
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || $host === '') {
            throw new InvalidArgumentException('Endereço de notificação inválido.');
        }
        if (in_array($host, self::DEFAULT_ALLOWED_HOSTS, true)) {
            return;
        }
        foreach (self::DEFAULT_ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return;
            }
        }
        throw new InvalidArgumentException('Serviço de notificação não reconhecido.');
    }

    /**
     * Corpo aes128gcm de um único registro (RFC 8291 §4). Sal e chave efêmera só são injetados em testes.
     */
    public static function encrypt(
        string $payload, string $userAgentPublic, string $authSecret,
        ?string $salt = null, ?array $ephemeral = null
    ): string {
        if (strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw new InvalidArgumentException('Conteúdo da notificação acima do limite.');
        }
        $salt ??= random_bytes(16);
        $ephemeral ??= self::generateKeyPair();
        $sharedSecret = openssl_pkey_derive(
            self::publicKey($userAgentPublic), self::privateKey($ephemeral['private'], $ephemeral['public'])
        );
        if ($sharedSecret === false || strlen($salt) !== 16 || strlen($authSecret) !== 16) {
            throw new RuntimeException('Falha no acordo de chaves ECDH.');
        }
        $keyInfo = "WebPush: info\x00" . $userAgentPublic . $ephemeral['public'];
        $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $authSecret);
        $contentKey = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $tag = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Falha ao cifrar a notificação.');
        }
        return $salt . pack('N', self::RECORD_SIZE) . chr(65) . $ephemeral['public'] . $cipher . $tag;
    }

    /** Cabeçalho Authorization "vapid t=..., k=..." para o endpoint informado. */
    public function vapidAuthorization(string $endpoint, ?int $now = null): string
    {
        $parts = parse_url($endpoint);
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = self::base64UrlEncode(json_encode([
            'aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $this->vapidSubject,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signature = '';
        if (!openssl_sign($header . '.' . $claims, $signature,
            self::privateKey($this->vapidPrivateKey, $this->vapidPublicKey), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Falha ao assinar o token VAPID.');
        }
        return 'vapid t=' . $header . '.' . $claims . '.' . self::base64UrlEncode(self::derToJose($signature))
            . ', k=' . self::base64UrlEncode($this->vapidPublicKey);
    }

    /**
     * Entrega ao serviço de push. Retorna o status HTTP (201 = aceito; 404/410 = inscrição extinta).
     * @param array{endpoint: string, p256dh: string, auth: string} $subscription
     */
    public function send(array $subscription, string $payload, string $topic = ''): int
    {
        self::assertAllowedEndpoint($subscription['endpoint']);
        $userAgentPublic = self::base64UrlDecode($subscription['p256dh']);
        $authSecret = self::base64UrlDecode($subscription['auth']);
        if ($userAgentPublic === null || $authSecret === null) {
            throw new InvalidArgumentException('Inscrição armazenada inválida.');
        }
        $body = self::encrypt($payload, $userAgentPublic, $authSecret);
        $headers = [
            'Authorization: ' . $this->vapidAuthorization($subscription['endpoint']),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($body),
            'TTL: ' . $this->ttlSeconds,
            'Urgency: high',
        ];
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', $topic) === 1) {
            $headers[] = 'Topic: ' . $topic;
        }
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => $this->timeoutSeconds, 'ignore_errors' => true, 'follow_location' => 0,
            'protocol_version' => 1.1,
        ]]);
        $stream = @fopen($subscription['endpoint'], 'rb', false, $context);
        if ($stream === false) {
            throw new RuntimeException('Serviço de push indisponível.');
        }
        try {
            $meta = stream_get_meta_data($stream);
            $statusLine = $meta['wrapper_data'][0] ?? '';
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$statusLine, $match) !== 1) {
                throw new RuntimeException('Resposta inválida do serviço de push.');
            }
            return (int)$match[1];
        } finally {
            fclose($stream);
        }
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr(rtrim($value, '='), '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }

    /** @return \OpenSSLAsymmetricKey */
    public static function publicKey(string $point): \OpenSSLAsymmetricKey
    {
        $der = hex2bin(self::SPKI_P256_PREFIX) . $point;
        $key = openssl_pkey_get_public(self::pem('PUBLIC KEY', $der));
        if ($key === false) {
            throw new InvalidArgumentException('Chave pública P-256 inválida.');
        }
        return $key;
    }

    private static function privateKey(string $secret, string $point): \OpenSSLAsymmetricKey
    {
        // SEC1 ECPrivateKey: versão 1, segredo de 32 bytes, curva prime256v1 e ponto público.
        $der = hex2bin('30770201010420') . $secret . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
        $key = openssl_pkey_get_private(self::pem('EC PRIVATE KEY', $der));
        if ($key === false) {
            throw new InvalidArgumentException('Chave privada P-256 inválida.');
        }
        return $key;
    }

    private static function pem(string $label, string $der): string
    {
        return "-----BEGIN $label-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END $label-----\n";
    }

    private static function pad32(string $value): string
    {
        // O OpenSSL devolve inteiros sem zeros à esquerda; coordenadas P-256 têm 32 bytes fixos.
        return str_pad($value, 32, "\x00", STR_PAD_LEFT);
    }

    /** Assinatura ECDSA DER (SEQUENCE{r, s}) → r||s de 64 bytes exigido pelo JWS ES256. */
    private static function derToJose(string $der): string
    {
        $offset = 0;
        $read = static function () use ($der, &$offset): string {
            if (ord($der[$offset++]) !== 0x02) {
                throw new RuntimeException('Assinatura DER inválida.');
            }
            $length = ord($der[$offset++]);
            $value = substr($der, $offset, $length);
            $offset += $length;
            return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('Assinatura DER inválida.');
        }
        $offset += ord($der[$offset]) & 0x80 ? 1 + (ord($der[$offset]) & 0x7f) : 1;
        return $read() . $read();
    }
}
