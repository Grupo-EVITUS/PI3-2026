<?php
declare(strict_types=1);

namespace Saa\Core;

use Closure;
use InvalidArgumentException;
use LogicException;
use Saa\Core\Exceptions\HttpException;
use Saa\Core\Exceptions\MethodNotAllowedException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Security\Permissions;

/**
 * Roteador. Registra rotas por método + caminho e resolve requisições.
 *
 *   $router->get('/students/{id:oid}', [StudentController::class, 'show'], ['permission' => Permissions::REGISTRY_VIEW]);
 *   $router->group(['api' => true, 'prefix' => '/api'], function (Router $r) { ... });
 *
 * Opções por rota: access = auth (padrão) | guest | public; permission (só com access=auth); api (erros em JSON);
 * csrf (padrão true em POST/PUT/PATCH/DELETE; false só no webhook); name.
 * Parâmetros: {id} = [^/]+ ; {id:oid} = 24 hexadecimais ; {id:int} = dígitos ; {token} = [A-Za-z0-9_-]{20,100}.
 * Opções de grupo: prefix, access, permission, api, csrf (valem para as rotas do grupo; a rota pode sobrescrever).
 */
final class Router
{
    public const ACCESS_AUTH = 'auth';
    public const ACCESS_GUEST = 'guest';
    public const ACCESS_PUBLIC = 'public';

    private const ACCESS_LEVELS = [self::ACCESS_AUTH, self::ACCESS_GUEST, self::ACCESS_PUBLIC];
    private const ROUTE_OPTIONS = ['access', 'permission', 'api', 'csrf', 'name'];
    private const GROUP_OPTIONS = ['prefix', 'access', 'permission', 'api', 'csrf'];
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];
    private const PARAM_TYPES = [
        'any' => '[^/]+',
        'oid' => '[A-Fa-f0-9]{24}',
        'int' => '[0-9]{1,18}',
        'token' => '[A-Za-z0-9_-]{20,100}',
    ];

    /** @var list<Route> */
    private array $routes = [];
    /** @var array<string, Route> */
    private array $named = [];
    /** @var list<array{prefix: string, options: array<string, mixed>}> */
    private array $groups = [];

    /** @param array{0: class-string, 1: string}|Closure $handler @param array<string, mixed> $options */
    public function get(string $path, array|Closure $handler, array $options = []): Route
    {
        return $this->add('GET', $path, $handler, $options);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler @param array<string, mixed> $options */
    public function post(string $path, array|Closure $handler, array $options = []): Route
    {
        return $this->add('POST', $path, $handler, $options);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler @param array<string, mixed> $options */
    public function put(string $path, array|Closure $handler, array $options = []): Route
    {
        return $this->add('PUT', $path, $handler, $options);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler @param array<string, mixed> $options */
    public function patch(string $path, array|Closure $handler, array $options = []): Route
    {
        return $this->add('PATCH', $path, $handler, $options);
    }

    /** @param array{0: class-string, 1: string}|Closure $handler @param array<string, mixed> $options */
    public function delete(string $path, array|Closure $handler, array $options = []): Route
    {
        return $this->add('DELETE', $path, $handler, $options);
    }

    /**
     * Agrupa rotas com prefixo e opções comuns.
     *
     * @param array<string, mixed> $options prefix, access, permission, api, csrf
     * @param callable(Router): void $callback
     */
    public function group(array $options, callable $callback): void
    {
        $this->assertKnownOptions($options, self::GROUP_OPTIONS, 'grupo');
        $parent = $this->currentGroup();
        $prefix = array_key_exists('prefix', $options) ? self::joinPaths($parent['prefix'], (string)$options['prefix']) : $parent['prefix'];
        unset($options['prefix']);
        $this->groups[] = ['prefix' => $prefix === '/' ? '' : $prefix, 'options' => array_replace($parent['options'], $options)];
        try {
            $callback($this);
        } finally {
            array_pop($this->groups);
        }
    }

    /** @return list<Route> todas as rotas, na ordem de registro (usado nos testes de matriz de permissões) */
    public function routes(): array
    {
        return $this->routes;
    }

    public function named(string $name): ?Route
    {
        return $this->named[$name] ?? null;
    }

    /** Há uma rota GET para o caminho? Menu e atalhos só mostram o que já existe. */
    public function has(string $path): bool
    {
        $path = substr($path, 0, strcspn($path, '?#'));
        try {
            $this->resolve('GET', $path);
            return true;
        } catch (HttpException) {
            return false;
        }
    }

    /**
     * Resolve método + caminho. HEAD usa as rotas GET. Rotas sem parâmetros têm prioridade sobre as dinâmicas.
     *
     * @return array{route: Route, params: array<string, string>}
     * @throws NotFoundException 404 se nenhum caminho casar
     * @throws MethodNotAllowedException 405 (com Allow) se o caminho existir para outros métodos
     */
    public function resolve(string $method, string $path): array
    {
        $method = strtoupper($method);
        $path = self::normalize($path);
        $accepted = $method === 'HEAD' ? ['HEAD', 'GET'] : [$method];
        $allowed = [];

        foreach ([false, true] as $dynamic) {
            foreach ($this->routes as $route) {
                if ($route->isDynamic() !== $dynamic || preg_match($route->regex, $path, $matches) !== 1) {
                    continue;
                }
                if (in_array($route->method, $accepted, true)) {
                    $params = [];
                    foreach ($route->paramNames as $name) {
                        $params[$name] = (string)$matches[$name];
                    }
                    return ['route' => $route, 'params' => $params];
                }
                $allowed[$route->method] = true;
            }
        }

        if ($allowed !== []) {
            if (isset($allowed['GET'])) {
                $allowed['HEAD'] = true;
            }
            $list = array_keys($allowed);
            sort($list);
            throw new MethodNotAllowedException($list);
        }
        throw new NotFoundException();
    }

    // ------------------------------------------------------------------ internos

    /**
     * @param array{0: class-string, 1: string}|Closure $handler
     * @param array<string, mixed> $options
     */
    private function add(string $method, string $path, array|Closure $handler, array $options): Route
    {
        $this->assertKnownOptions($options, self::ROUTE_OPTIONS, 'rota');
        $group = $this->currentGroup();
        $merged = array_replace($group['options'], $options);
        $fullPath = self::normalize(self::joinPaths($group['prefix'], $path));

        if (!$handler instanceof Closure && (count($handler) !== 2 || !isset($handler[0], $handler[1]) || !is_string($handler[0]) || !is_string($handler[1]))) {
            throw new InvalidArgumentException("Manipulador inválido para {$method} {$fullPath}: use [Controller::class, 'metodo'] ou closure.");
        }

        $access = $merged['access'] ?? self::ACCESS_AUTH;
        if (!is_string($access) || !in_array($access, self::ACCESS_LEVELS, true)) {
            throw new InvalidArgumentException("Opção access inválida em {$method} {$fullPath}: use auth, guest ou public.");
        }
        $permission = $merged['permission'] ?? null;
        if ($permission !== null) {
            if (!is_string($permission) || !array_key_exists($permission, Permissions::all())) {
                throw new InvalidArgumentException("Permissão desconhecida em {$method} {$fullPath}: " . (is_string($permission) ? $permission : gettype($permission)));
            }
            if ($access !== self::ACCESS_AUTH) {
                throw new InvalidArgumentException("A opção permission só vale com access=auth ({$method} {$fullPath}).");
            }
        }
        $csrf = $merged['csrf'] ?? in_array($method, self::WRITE_METHODS, true);
        $name = $options['name'] ?? null;
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new InvalidArgumentException("Nome de rota inválido em {$method} {$fullPath}.");
        }
        if ($name !== null && isset($this->named[$name])) {
            throw new LogicException("Já existe uma rota com o nome {$name}.");
        }
        foreach ($this->routes as $existing) {
            if ($existing->method === $method && $existing->path === $fullPath) {
                throw new LogicException("Rota duplicada: {$method} {$fullPath}.");
            }
        }

        [$regex, $paramNames] = self::compile($fullPath);
        $route = new Route($method, $fullPath, $handler, $access, $permission, (bool)($merged['api'] ?? false), (bool)$csrf, $name, $regex, $paramNames);
        $this->routes[] = $route;
        if ($name !== null) {
            $this->named[$name] = $route;
        }
        return $route;
    }

    /** @return array{prefix: string, options: array<string, mixed>} */
    private function currentGroup(): array
    {
        return $this->groups === [] ? ['prefix' => '', 'options' => []] : $this->groups[array_key_last($this->groups)];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $known
     */
    private function assertKnownOptions(array $options, array $known, string $where): void
    {
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Opção desconhecida em ' . $where . ': ' . implode(', ', array_map('strval', $unknown)));
        }
    }

    private static function joinPaths(string $prefix, string $path): string
    {
        return rtrim($prefix, '/') . '/' . ltrim($path, '/');
    }

    /** Barra inicial obrigatória, sem barra final (exceto a raiz). */
    private static function normalize(string $path): string
    {
        $trimmed = trim($path, '/');
        return $trimmed === '' ? '/' : '/' . $trimmed;
    }

    /** @return array{0: string, 1: list<string>} expressão regular e nomes dos parâmetros */
    private static function compile(string $pattern): array
    {
        $names = [];
        $regex = '';
        $offset = 0;
        $found = preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)(?::([a-z]+))?\}/', $pattern, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($found ? $matches : [] as $match) {
            $start = $match[0][1];
            $regex .= preg_quote(substr($pattern, $offset, $start - $offset), '#');
            $name = $match[1][0];
            $type = isset($match[2]) ? $match[2][0] : ($name === 'token' ? 'token' : 'any');
            if (!isset(self::PARAM_TYPES[$type])) {
                throw new InvalidArgumentException("Tipo de parâmetro desconhecido em {$pattern}: {$type}");
            }
            if (in_array($name, $names, true)) {
                throw new InvalidArgumentException("Parâmetro repetido em {$pattern}: {$name}");
            }
            $names[] = $name;
            $regex .= '(?P<' . $name . '>' . self::PARAM_TYPES[$type] . ')';
            $offset = $start + strlen($match[0][0]);
        }
        $regex .= preg_quote(substr($pattern, $offset), '#');
        return ['#^' . $regex . '$#D', $names];
    }
}
