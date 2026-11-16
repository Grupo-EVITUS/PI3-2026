<?php
declare(strict_types=1);

use Saa\Push\WebPush;

/** Papel do navegador nos testes: decifra o corpo e confere o token VAPID recebido. */
final class PushTestSupport
{
    /** @param array{public: string, private: string} $userAgent */
    public static function decrypt(string $body, array $userAgent, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $serverPublic = substr($body, 21, $idLength);
        $encrypted = substr($body, 21 + $idLength);
        $ecdh = openssl_pkey_derive(WebPush::publicKey($serverPublic), self::privateKey($userAgent));
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\x00" . $userAgent['public'] . $serverPublic, $authSecret);
        $key = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);
        $plain = openssl_decrypt(substr($encrypted, 0, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce,
            substr($encrypted, -16));
        if ($plain === false || !str_ends_with($plain, "\x02")) {
            throw new RuntimeException('Corpo Web Push não decifrável.');
        }
        return rtrim(substr($plain, 0, -1), "\x00");
    }

    /** @return array{claims: array, k: string}|null */
    public static function verifyVapid(string $authorization): ?array
    {
        if (preg_match('/^vapid t=([^,]+), k=([A-Za-z0-9_-]+)$/', $authorization, $match) !== 1) {
            return null;
        }
        [$header, $claims, $signature] = explode('.', $match[1]) + [null, null, null];
        $public = WebPush::base64UrlDecode($match[2]);
        $raw = WebPush::base64UrlDecode((string)$signature);
        if ($public === null || $raw === null || strlen($raw) !== 64
            || json_decode((string)WebPush::base64UrlDecode((string)$header), true) !== ['typ' => 'JWT', 'alg' => 'ES256']) {
            return null;
        }
        $integer = static function (string $value): string {
            $value = ltrim($value, "\x00");
            if ($value === '' || ord($value[0]) & 0x80) {
                $value = "\x00" . $value;
            }
            return "\x02" . chr(strlen($value)) . $value;
        };
        $sequence = $integer(substr($raw, 0, 32)) . $integer(substr($raw, 32));
        $der = "\x30" . chr(strlen($sequence)) . $sequence;
        if (openssl_verify($header . '.' . $claims, $der, WebPush::publicKey($public), OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }
        return ['claims' => json_decode((string)WebPush::base64UrlDecode((string)$claims), true), 'k' => $public];
    }

    /** @param array{public: string, private: string} $pair */
    private static function privateKey(array $pair): OpenSSLAsymmetricKey
    {
        $der = hex2bin('30770201010420') . $pair['private'] . hex2bin('a00a06082a8648ce3d030107a144034200') . $pair['public'];
        $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
        return openssl_pkey_get_private($pem) ?: throw new RuntimeException('Chave de teste inválida.');
    }
}
