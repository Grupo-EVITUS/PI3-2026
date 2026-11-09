<?php
declare(strict_types=1);

namespace Saa\Core;

/**
 * Configuração da aplicação a partir de variáveis de ambiente (RNF-14).
 *
 * Ordem de busca: valores definidos por Config::set() (testes) → getenv()/$_ENV →
 * arquivo .env na raiz do projeto (nunca sobrescreve o ambiente real) → padrão informado.
 * Nenhuma credencial deve ser versionada: use .env (ignorado pelo Git) ou variáveis do ambiente.
 */
final class Config
{
    /** @var array<string, string|null> */
    private static array $overrides = [];
    /** @var array<string, string>|null */
    private static ?array $dotenv = null;

    /** Raiz do projeto (pasta que contém composer.json). */
    public static function basePath(string $path = ''): string
    {
        $root = dirname(__DIR__, 2);
        return $path === '' ? $root : $root . '/' . ltrim($path, '/\\');
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key] ?? $default;
        }
        $value = getenv($key);
        if ($value === false && isset($_ENV[$key]) && is_string($_ENV[$key])) {
            $value = $_ENV[$key];
        }
        if ($value !== false && $value !== '') {
            return (string)$value;
        }
        $dotenv = self::dotenv();
        if (isset($dotenv[$key]) && $dotenv[$key] !== '') {
            return $dotenv[$key];
        }
        return $default;
    }

    public static function string(string $key, string $default = ''): string
    {
        return self::get($key, $default) ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on', 'sim'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        if ($value === null || !preg_match('/^-?\d+$/', trim($value))) {
            return $default;
        }
        return (int)trim($value);
    }

    /** Define (ou, com null, remove) um valor somente para o processo atual — usado nos testes. */
    public static function set(string $key, ?string $value): void
    {
        self::$overrides[$key] = $value;
    }

    /** Limpa valores definidos por set() e o cache do .env. */
    public static function reset(): void
    {
        self::$overrides = [];
        self::$dotenv = null;
    }

    /** development | test | production */
    public static function env(): string
    {
        $env = strtolower(self::string('APP_ENV', 'development'));
        return in_array($env, ['development', 'test', 'production'], true) ? $env : 'development';
    }

    public static function isProduction(): bool
    {
        return self::env() === 'production';
    }

    /** Fuso usado para "hoje" e para exibir datas. O armazenamento é sempre em UTC. */
    public static function timezone(): string
    {
        $zone = self::string('APP_TIMEZONE', 'America/Sao_Paulo');
        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'America/Sao_Paulo';
    }

    /** @return array<string, string> */
    private static function dotenv(): array
    {
        if (self::$dotenv !== null) {
            return self::$dotenv;
        }
        self::$dotenv = [];
        $file = self::basePath('.env');
        if (!is_file($file) || !is_readable($file)) {
            return self::$dotenv;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                self::$dotenv[$name] = $value;
            }
        }
        return self::$dotenv;
    }
}
