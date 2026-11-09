<?php
declare(strict_types=1);

namespace Saa\Core;

use Saa\Core\Exceptions\BadRequestException;
use Saa\Core\Exceptions\HttpException;

/**
 * Requisição HTTP imutável (os "with*" devolvem cópias).
 *
 * Getters tipados (string, int, bool, stringList) são SEGUROS contra injeção de operadores NoSQL:
 * qualquer valor que não seja escalar do tipo esperado (por exemplo email[$ne]=x, que o PHP converte em
 * array) é ignorado. Nunca coloque input()/all()/query() crus em um filtro do MongoDB.
 */
final class Request
{
    /** Limite do corpo JSON lido de php://input. */
    public const MAX_BODY_BYTES = 2097152;

    /** @var array<string, mixed>|null */
    private ?array $body = null;
    private readonly string $path;
    /** @var array<string, string> */
    private array $params = [];
    /** @var array{id: string, name: string, email: string, profile: string}|null */
    private ?array $user = null;

    /**
     * @param array<string, mixed> $query parâmetros da URL ($_GET)
     * @param array<string, mixed> $post corpo de formulário ($_POST)
     * @param array<string, mixed> $server variáveis de servidor ($_SERVER)
     * @param array<string, mixed> $cookies cookies ($_COOKIE)
     */
    public function __construct(
        private readonly string $method = 'GET',
        private readonly string $uri = '/',
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $server = [],
        private readonly array $cookies = [],
        private readonly string $rawBody = '',
    ) {
        $this->path = self::parsePath($uri);
    }

    public static function fromGlobals(): self
    {
        $server = $_SERVER;
        $contentType = strtolower((string)($server['CONTENT_TYPE'] ?? ''));
        $rawBody = str_contains($contentType, 'json') ? self::readInput() : '';
        return new self(
            strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET')),
            (string)($server['REQUEST_URI'] ?? '/'),
            $_GET,
            $_POST,
            $server,
            $_COOKIE,
            $rawBody
        );
    }

    /**
     * Cria uma requisição a partir de partes simples (testes e scripts). Em GET/HEAD, $data vira query string;
     * nos demais métodos, vira corpo de formulário. Com $json, o corpo é JSON.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $server
     * @param array<string, string> $cookies
     */
    public static function create(
        string $method,
        string $uri,
        array $data = [],
        array $server = [],
        array $cookies = [],
        ?string $json = null,
    ): self {
        $method = strtoupper($method);
        $query = [];
        $queryString = '';
        $position = strpos($uri, '?');
        if ($position !== false) {
            $queryString = substr($uri, $position + 1);
        }
        $post = [];
        if ($method === 'GET' || $method === 'HEAD') {
            if ($data !== []) {
                $queryString .= ($queryString === '' ? '' : '&') . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                $uri = ($position === false ? $uri : substr($uri, 0, $position)) . '?' . $queryString;
            }
        } else {
            $post = $data;
        }
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }
        $server += [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
            'SERVER_PORT' => '80',
        ];
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        return new self($method, $uri, $query, $post, $server, $cookies, $json ?? '');
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    /** URI como recebida (caminho + query string, sem decodificar). Use SafeRedirect antes de redirecionar para ela. */
    public function uri(): string
    {
        return $this->uri;
    }

    /** Caminho decodificado, sem query string (ex.: "/students/5"). */
    public function path(): string
    {
        return $this->path;
    }

    public function isSecure(): bool
    {
        $https = strtolower((string)($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (string)($this->server['SERVER_PORT'] ?? '') === '443';
    }

    /** Host da requisição, validado (usado só como último recurso; prefira APP_URL). */
    public function host(): string
    {
        $host = (string)($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? '');
        return preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$|^\[[0-9A-Fa-f:.]+\](:\d{1,5})?$/', $host) === 1 ? $host : 'localhost';
    }

    /** Endereço do cliente: somente REMOTE_ADDR (cabeçalhos de proxy não são confiáveis). */
    public function ip(): string
    {
        $ip = (string)($this->server['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = strtoupper(str_replace('-', '_', $name));
        if ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
            $key = 'HTTP_' . $key;
        }
        $value = $this->server[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        $value = $this->cookies[$name] ?? null;
        return is_string($value) ? $value : $default;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type') ?? ''), 'json');
    }

    /** O cliente prefere JSON (Accept: application/json antes de text/html, ou XMLHttpRequest). */
    public function wantsJson(): bool
    {
        if (strtolower($this->header('X-Requested-With') ?? '') === 'xmlhttprequest') {
            return true;
        }
        $accept = strtolower($this->header('Accept') ?? '');
        if ($accept === '') {
            return $this->isJson();
        }
        $json = strpos($accept, 'application/json');
        if ($json === false) {
            return false;
        }
        $html = strpos($accept, 'text/html');
        return $html === false || $json < $html;
    }

    // ------------------------------------------------------------------ entrada bruta

    /** @return array<string, mixed> parâmetros da query string */
    public function queryAll(): array
    {
        return $this->query;
    }

    /** Valor BRUTO da query string (pode ser array). Para uso seguro prefira string()/int()/bool(). */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Corpo da requisição: JSON decodificado (Content-Type JSON) ou formulário.
     *
     * @return array<string, mixed>
     * @throws BadRequestException se o corpo JSON for inválido
     * @throws HttpException 413 se o corpo for grande demais
     */
    public function body(): array
    {
        if ($this->body !== null) {
            return $this->body;
        }
        if (!$this->isJson()) {
            return $this->body = $this->post;
        }
        if (strlen($this->rawBody) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'O corpo da requisição é grande demais.');
        }
        if (trim($this->rawBody) === '') {
            return $this->body = [];
        }
        $decoded = json_decode($this->rawBody, true, 32);
        if (!is_array($decoded)) {
            throw new BadRequestException('Corpo JSON inválido.');
        }
        return $this->body = $decoded;
    }

    /** Valor BRUTO do corpo (pode ser array). */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $default;
    }

    /** Valor BRUTO: corpo primeiro, depois a query string. */
    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->body();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        return $this->query[$key] ?? $default;
    }

    /**
     * Query string + corpo (o corpo prevalece). Valores brutos: use com Validator, nunca em filtros do banco.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->body());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body()) || array_key_exists($key, $this->query);
    }

    // ------------------------------------------------------------------ getters tipados (seguros)

    /**
     * Texto do campo ou null se ausente ou não for texto (arrays/objetos são IGNORADOS). Números JSON viram texto.
     * Por padrão remove espaços nas pontas; passe $trim=false para senhas.
     */
    public function string(string $key, bool $trim = true): ?string
    {
        $value = $this->input($key);
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            return null;
        }
        return $trim ? trim($value) : $value;
    }

    /** Inteiro do campo (aceita int ou texto "123"/"-5") ou $default. */
    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->input($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d{1,18}\s*$/', $value) === 1) {
            return (int)trim($value);
        }
        return $default;
    }

    /** true para 1/"1"/"true"/"on"/"yes"/"sim"; false para 0/"0"/"false"/"off"/"no"/"nao"/""; senão $default. */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : $default);
        }
        if (!is_string($value)) {
            return $default;
        }
        return match (strtolower(trim($value))) {
            '1', 'true', 'on', 'yes', 'sim' => true,
            '0', 'false', 'off', 'no', 'nao', 'não', '' => false,
            default => $default,
        };
    }

    /**
     * Lista de textos (checkbox/select múltiplo). Aceita um texto isolado; ignora elementos que não sejam texto.
     *
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->input($key);
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = trim($item);
            }
        }
        return $list;
    }

    // ------------------------------------------------------------------ parâmetros de rota e usuário

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return $this->params;
    }

    /** @param array<string, string> $params */
    public function withParams(array $params): self
    {
        $copy = clone $this;
        $copy->params = $params;
        return $copy;
    }

    /** Usuário autenticado (definido pelo Kernel): id, name, email, profile — ou null para visitantes. */
    public function user(): ?array
    {
        return $this->user;
    }

    /** @param array{id: string, name: string, email: string, profile: string}|null $user */
    public function withUser(?array $user): self
    {
        $copy = clone $this;
        $copy->user = $user;
        return $copy;
    }

    // ------------------------------------------------------------------ internos

    private static function parsePath(string $uri): string
    {
        $path = substr($uri, 0, strcspn($uri, '?#'));
        // forma absoluta ("GET http://host/caminho"): descarta esquema e host
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://[^/]*(/.*)?$#', $path, $matches) === 1) {
            $path = $matches[1] ?? '/';
        }
        $path = rawurldecode($path);
        $path = str_replace("\0", '', $path);
        return $path === '' || $path[0] !== '/' ? '/' . $path : $path;
    }

    private static function readInput(): string
    {
        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            return '';
        }
        $data = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        fclose($stream);
        return $data === false ? '' : $data;
    }
}
