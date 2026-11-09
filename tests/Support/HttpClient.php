<?php
declare(strict_types=1);

namespace Saa\Tests\Support;

use RuntimeException;

/**
 * Cliente HTTP mínimo (cURL) para os testes de aceitação: não segue redirecionamentos e mantém um cookie jar
 * próprio (nome => valor), respeitando a remoção por Max-Age/Expires. Não usa curl_close() (obsoleto no PHP 8.5).
 */
final class HttpClient
{
    /** @var array<string, string> */
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    /** @param array<string, string> $headers */
    public function get(string $path, array $headers = [], bool $pathAsIs = false): HttpResponse
    {
        return $this->request('GET', $path, null, $headers, $pathAsIs);
    }

    /**
     * POST de formulário. Com array, o corpo é codificado como application/x-www-form-urlencoded;
     * com string, é enviado exatamente como está (útil para "email[$ne]=x").
     *
     * @param array<string, mixed>|string $fields
     * @param array<string, string> $headers
     */
    public function post(string $path, array|string $fields = [], array $headers = []): HttpResponse
    {
        $body = is_string($fields) ? $fields : http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        return $this->request('POST', $path, $body, ['Content-Type' => 'application/x-www-form-urlencoded'] + $headers);
    }

    /** @param array<string, string> $headers */
    public function postJson(string $path, string $json, array $headers = []): HttpResponse
    {
        return $this->request('POST', $path, $json, ['Content-Type' => 'application/json', 'Accept' => 'application/json'] + $headers);
    }

    /** @param array<string, string> $headers */
    public function request(string $method, string $path, ?string $body = null, array $headers = [], bool $pathAsIs = false): HttpResponse
    {
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new RuntimeException('Não foi possível iniciar o cURL.');
        }
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $lines[] = 'Cookie: ' . implode('; ', $pairs);
        }
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_PATH_AS_IS => $pathAsIs,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];  // nova resposta (ex.: 100-continue seguido da final)
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))][] = trim($value);
                }
                return $length;
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        }
        $content = curl_exec($handle);
        if ($content === false) {
            $error = curl_error($handle);
            throw new RuntimeException("Falha na requisição {$method} {$path}: {$error}");
        }
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        $response = new HttpResponse($status, $responseHeaders, (string)$content);
        $this->storeCookies($response);
        return $response;
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function setCookie(string $name, string $value): void
    {
        $this->cookies[$name] = $value;
    }

    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    private function storeCookies(HttpResponse $response): void
    {
        foreach ($response->headerValues('set-cookie') as $line) {
            $parts = array_map('trim', explode(';', $line));
            $pair = array_shift($parts);
            if ($pair === null || !str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $expired = false;
            foreach ($parts as $attribute) {
                $lower = strtolower($attribute);
                if (str_starts_with($lower, 'max-age=') && (int)substr($lower, 8) <= 0) {
                    $expired = true;
                }
                if (str_starts_with($lower, 'expires=')) {
                    $time = strtotime(substr($attribute, 8));
                    if ($time !== false && $time < time()) {
                        $expired = true;
                    }
                }
            }
            if ($expired || $value === '' || $value === 'deleted') {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
    }
}
