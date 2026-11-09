<?php
declare(strict_types=1);

namespace Saa\Core;

use LogicException;
use RuntimeException;

/**
 * Sessão da aplicação, em dois modos:
 *  - native(): sessão nativa do PHP (cookie SAASESSID HttpOnly + SameSite=Lax, modo estrito, Secure conforme
 *    SESSION_COOKIE_SECURE ou HTTPS). Usada em requisições web;
 *  - memory(): dados em memória, sem cookie nem arquivos (testes e scripts de linha de comando).
 *
 * Expira por inatividade (SESSION_IDLE_MINUTES, padrão 120) e por duração absoluta (SESSION_ABSOLUTE_HOURS,
 * padrão 12). Uma sessão autenticada que expira é descartada e expiredReason() informa o motivo na requisição.
 * Guarde na sessão apenas dados serializáveis e pequenos (id do usuário, token CSRF, mensagens flash).
 */
final class Session
{
    public const COOKIE_NAME = 'SAASESSID';
    /** Chave com o id (hex) do usuário autenticado. */
    public const USER_KEY = 'auth.user_id';

    private const KEY_CREATED = '_created_at';
    private const KEY_SEEN = '_last_seen_at';
    private const KEY_FLASH = '_flash';
    private const FLASH_KINDS = ['success', 'error', 'info', 'warning'];

    /** @var array<string, mixed> */
    private array $memory = [];
    private string $memoryId = '';
    private bool $started = false;
    private ?string $expiredReason = null;
    private int $regenerations = 0;

    private function __construct(private readonly bool $native)
    {
    }

    public static function native(): self
    {
        return new self(true);
    }

    public static function memory(): self
    {
        return new self(false);
    }

    public function isNative(): bool
    {
        return $this->native;
    }

    /**
     * Abre a sessão (se necessário) e aplica os tempos de expiração. Pode ser chamado a cada requisição.
     */
    public function start(bool $secure = false): void
    {
        $this->expiredReason = null;
        if ($this->native) {
            $this->startNative($secure);
        } elseif ($this->memoryId === '') {
            $this->memoryId = bin2hex(random_bytes(16));
        }
        $this->started = true;
        $this->applyTimeouts();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): string
    {
        if ($this->native) {
            return session_status() === PHP_SESSION_ACTIVE ? session_id() : '';
        }
        return $this->memoryId;
    }

    /** Quantas vezes o identificador foi regenerado (útil em testes de fixação de sessão). */
    public function regenerationCount(): int
    {
        return $this->regenerations;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->started) {
            return $default;
        }
        $store = &$this->store();
        return $store[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function put(string $key, mixed $value): void
    {
        $this->assertStarted();
        $store = &$this->store();
        $store[$key] = $value;
    }

    public function forget(string $key): void
    {
        if (!$this->started) {
            return;
        }
        $store = &$this->store();
        unset($store[$key]);
    }

    /** Lê e remove. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /** Id (hex) do usuário autenticado ou null. */
    public function userId(): ?string
    {
        $id = $this->get(self::USER_KEY);
        return is_string($id) && $id !== '' ? $id : null;
    }

    public function setUserId(string $id): void
    {
        $this->put(self::USER_KEY, $id);
    }

    /** Mensagem exibida uma única vez na próxima página (success | error | info | warning). */
    public function flash(string $kind, string $message): void
    {
        $kind = in_array($kind, self::FLASH_KINDS, true) ? $kind : 'info';
        $flashes = $this->get(self::KEY_FLASH);
        $flashes = is_array($flashes) ? $flashes : [];
        $flashes[] = ['kind' => $kind, 'message' => $message];
        $this->put(self::KEY_FLASH, $flashes);
    }

    /**
     * Lê e remove as mensagens flash.
     *
     * @return list<array{kind: string, message: string}>
     */
    public function pullFlashes(): array
    {
        $flashes = $this->pull(self::KEY_FLASH);
        if (!is_array($flashes)) {
            return [];
        }
        $messages = [];
        foreach ($flashes as $flash) {
            if (is_array($flash) && is_string($flash['kind'] ?? null) && is_string($flash['message'] ?? null)) {
                $messages[] = ['kind' => $flash['kind'], 'message' => $flash['message']];
            }
        }
        return $messages;
    }

    /** Novo identificador de sessão mantendo os dados (evita fixação de sessão). */
    public function regenerate(): void
    {
        if ($this->native) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
        } else {
            $this->memoryId = bin2hex(random_bytes(16));
        }
        $this->regenerations++;
    }

    /**
     * Descarta todos os dados e troca o identificador (login, logout, expiração). $reason fica disponível em
     * expiredReason() até o fim da requisição (ex.: "revoked" quando o usuário foi desativado).
     */
    public function invalidate(?string $reason = null): void
    {
        $this->assertStarted();
        $this->flush();
        $this->regenerate();
        $now = Clock::now()->getTimestamp();
        $this->put(self::KEY_CREATED, $now);
        $this->put(self::KEY_SEEN, $now);
        $this->expiredReason = $reason;
    }

    /** Remove todos os dados (sem trocar o identificador). */
    public function flush(): void
    {
        if (!$this->started) {
            return;
        }
        $store = &$this->store();
        $store = [];
    }

    /** idle | absolute | revoked quando a sessão de um usuário autenticado foi encerrada nesta requisição. */
    public function expiredReason(): ?string
    {
        return $this->expiredReason;
    }

    /** Grava e libera a sessão. Chamado pelo Kernel antes de enviar a resposta. */
    public function save(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    // ------------------------------------------------------------------ internos

    /** @return array<string, mixed> */
    private function &store(): array
    {
        if ($this->native) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return $_SESSION;
        }
        return $this->memory;
    }

    private function assertStarted(): void
    {
        if (!$this->started) {
            throw new LogicException('A sessão ainda não foi iniciada.');
        }
    }

    private function applyTimeouts(): void
    {
        $now = Clock::now()->getTimestamp();
        $created = $this->get(self::KEY_CREATED);
        $seen = $this->get(self::KEY_SEEN);
        if (is_int($created) && is_int($seen)) {
            $absolute = max(1, Config::int('SESSION_ABSOLUTE_HOURS', 12)) * 3600;
            $idle = max(1, Config::int('SESSION_IDLE_MINUTES', 120)) * 60;
            $reason = null;
            if ($now - $created > $absolute) {
                $reason = 'absolute';
            } elseif ($now - $seen > $idle) {
                $reason = 'idle';
            }
            if ($reason !== null) {
                $hadUser = $this->userId() !== null;
                $this->flush();
                $this->regenerate();
                $this->expiredReason = $hadUser ? $reason : null;
                $created = null;
            }
        }
        if (!is_int($created)) {
            $this->put(self::KEY_CREATED, $now);
        }
        $this->put(self::KEY_SEEN, $now);
    }

    private function startNative(bool $secure): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent($file, $line)) {
            throw new RuntimeException("Não foi possível iniciar a sessão: a saída já começou em {$file}:{$line}.");
        }
        $lifetime = max(1, Config::int('SESSION_ABSOLUTE_HOURS', 12)) * 3600 + 3600;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_name(self::COOKIE_NAME);
        // O Kernel define Cache-Control; sem isso o PHP envia seus próprios cabeçalhos de cache.
        session_cache_limiter('');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure || Config::bool('SESSION_COOKIE_SECURE'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $path = self::savePath();
        if ($path !== null) {
            session_save_path($path);
        }
        if (!session_start()) {
            throw new RuntimeException('Não foi possível iniciar a sessão.');
        }
    }

    /**
     * Pasta dos arquivos de sessão: SESSION_SAVE_PATH, ou var/sessions (criada com permissão restrita) quando
     * possível; null mantém o padrão do PHP.
     */
    private static function savePath(): ?string
    {
        $configured = Config::get('SESSION_SAVE_PATH');
        $explicit = $configured !== null && $configured !== '';
        $path = $explicit ? $configured : Config::basePath('var/sessions');
        if (!is_dir($path)) {
            @mkdir($path, 0700, true);
        }
        if (is_dir($path) && is_writable($path)) {
            return $path;
        }
        if ($explicit) {
            throw new RuntimeException('SESSION_SAVE_PATH não existe ou não é gravável: ' . $path);
        }
        return null;
    }
}
