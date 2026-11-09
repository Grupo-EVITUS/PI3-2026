<?php
declare(strict_types=1);

namespace Saa\Core;

use Closure;
use ErrorException;
use LogicException;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionException;
use RuntimeException;
use Saa\Core\Exceptions\AuthenticationException;
use Saa\Core\Exceptions\ForbiddenException;
use Saa\Core\Exceptions\HttpException;
use Saa\Core\Exceptions\ValidationException;
use Saa\Database\Mongo;
use Saa\Security\Permissions;
use Saa\Services\AuthService;
use Saa\Support\SafeRedirect;
use Throwable;

/**
 * Aplicação: monta o Container e as rotas (App::boot) e executa o Kernel (App::handle / App::run).
 *
 * Ciclo de uma requisição (docs/ARQUITETURA.md, seção 4):
 *   sessão (timeouts) → resolução da rota (404/405) → usuário (relido do banco a cada requisição; inativo encerra a
 *   sessão) → CSRF (métodos que escrevem, exceto csrf=false) → acesso (public | guest | auth + permissão) → Controller.
 * Exceções viram HTTP: 400/401/403/404/405/409/422/429 conforme a família HttpException; falha de conexão com o
 * MongoDB → 503; demais Throwable → 500 (registrado em error_log; só APP_ENV=development mostra detalhes).
 * Respostas JSON para rotas api, URLs /api/* e Accept: application/json; HTML (views/errors/error.php) nos demais casos.
 * Todas as respostas recebem os cabeçalhos de segurança (SecurityHeaders).
 */
final class App
{
    private const ERROR_TITLES = [
        400 => 'Solicitação inválida',
        401 => 'Autenticação necessária',
        403 => 'Acesso negado',
        404 => 'Página não encontrada',
        405 => 'Método não permitido',
        409 => 'Conflito de dados',
        413 => 'Conteúdo grande demais',
        422 => 'Dados inválidos',
        429 => 'Muitas tentativas',
        500 => 'Erro inesperado',
        503 => 'Serviço indisponível',
    ];
    private const GENERIC_ERROR = 'Ocorreu um erro inesperado. Tente novamente em instantes; se o problema continuar, avise o administrador.';

    private static ?self $current = null;

    private ?Request $request = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $navigation = null;

    private function __construct(
        private readonly Container $container,
        private readonly Router $router,
        private readonly Session $session,
        private readonly string $navigationFile,
    ) {
    }

    /**
     * Cria a aplicação: registra os serviços compartilhados e carrega config/routes/*.php (ordem alfabética).
     *
     * @param array{views?: string, routes?: string, navigation?: string, session?: Session, container?: Container} $options
     *        views/navigation também podem vir de VIEWS_PATH/NAVIGATION_FILE (ganchos de teste); session padrão:
     *        nativa na web, em memória na linha de comando.
     */
    public static function boot(array $options = []): self
    {
        $container = $options['container'] ?? new Container();
        $router = new Router();
        $session = $options['session'] ?? (PHP_SAPI === 'cli' ? Session::memory() : Session::native());
        $views = $options['views'] ?? Config::get('VIEWS_PATH') ?? Config::basePath('views');
        $navigation = $options['navigation'] ?? Config::get('NAVIGATION_FILE') ?? Config::basePath('config/navigation.php');
        $routes = $options['routes'] ?? Config::basePath('config/routes');

        $container->instance(Container::class, $container);
        $container->instance(Router::class, $router);
        $container->instance(Session::class, $session);
        $container->instance(View::class, new View($views));
        $container->singleton(Csrf::class);
        $container->singleton(Database::class, static fn (): Database => Mongo::database());

        $app = new self($container, $router, $session, $navigation);
        $container->instance(self::class, $app);
        self::loadRoutes($router, $routes);
        self::$current = $app;
        return $app;
    }

    /** Ponto de entrada web (public/index.php e router.php). */
    public static function run(): void
    {
        self::configureRuntime();
        $request = null;
        try {
            $app = self::boot();
            $request = Request::fromGlobals();
            $app->handle($request)->send($request->method() === 'HEAD');
        } catch (Throwable $error) {
            error_log(sprintf("[SAA] Falha ao iniciar: %s: %s in %s:%d\n%s", $error::class, $error->getMessage(), $error->getFile(), $error->getLine(), $error->getTraceAsString()));
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
                header('Cache-Control: no-store');
            }
            echo self::fallbackPage(500, self::ERROR_TITLES[500], self::GENERIC_ERROR);
        }
    }

    /** Aplicação da requisição em andamento (usada pelos helpers das views). */
    public static function current(): ?self
    {
        return self::$current;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function csrf(): Csrf
    {
        return $this->container->get(Csrf::class);
    }

    public function view(): View
    {
        return $this->container->get(View::class);
    }

    /** Requisição em andamento (null antes do primeiro handle()). */
    public function request(): ?Request
    {
        return $this->request;
    }

    /** @return list<array<string, mixed>> itens de config/navigation.php */
    public function navigationConfig(): array
    {
        return $this->navigation ??= Navigation::load($this->navigationFile);
    }

    /** Executa o Kernel para uma requisição e devolve a resposta (sem enviá-la). */
    public function handle(Request $request): Response
    {
        self::$current = $this;
        $this->bind($request);
        $route = null;
        try {
            $this->session->start($request->isSecure());
            $resolved = $this->router->resolve($request->method(), $request->path());
            $route = $resolved['route'];
            $this->bind($this->currentRequest()->withParams($resolved['params']));
            if ($route->access !== Router::ACCESS_PUBLIC) {
                $this->bind($this->currentRequest()->withUser($this->resolveUser()));
            }
            if ($route->csrf) {
                $this->csrf()->verify($this->currentRequest());
            }
            $response = $this->guard($route, $this->currentRequest()) ?? $this->dispatch($route, $this->currentRequest());
        } catch (Throwable $error) {
            $response = $this->errorResponse($error, $route);
        } finally {
            $this->session->save();
        }
        return SecurityHeaders::apply($response, $this->currentRequest());
    }

    // ------------------------------------------------------------------ Kernel

    /** Usuário autenticado (relido do banco: desativar ou trocar o perfil vale na hora) ou null. */
    private function resolveUser(): ?array
    {
        if ($this->session->userId() === null) {
            return null;
        }
        return $this->container->get(AuthService::class)->currentUser();
    }

    /** Controle de acesso: devolve um redirecionamento ou lança 401/403; null libera a rota. */
    private function guard(Route $route, Request $request): ?Response
    {
        $user = $request->user();
        if ($route->access === Router::ACCESS_PUBLIC) {
            return null;
        }
        if ($route->access === Router::ACCESS_GUEST) {
            return $user !== null ? Response::redirect('/home') : null;
        }
        if ($user === null) {
            if ($route->api || $request->wantsJson()) {
                throw new AuthenticationException('Autenticação necessária. Entre no sistema para continuar.');
            }
            return $this->redirectToLogin($request);
        }
        if ($route->permission !== null && !Permissions::can($user['profile'], $route->permission)) {
            throw new ForbiddenException();
        }
        return null;
    }

    private function redirectToLogin(Request $request): Response
    {
        $reason = $this->session->expiredReason();
        if ($reason !== null) {
            $this->session->flash('info', $reason === 'revoked' ? 'Seu acesso foi encerrado. Entre novamente.' : 'Sua sessão expirou. Entre novamente.');
        }
        $target = '/login';
        if (in_array($request->method(), ['GET', 'HEAD'], true)) {
            $next = SafeRedirect::next($request->uri());
            if ($next !== null) {
                $target .= '?next=' . rawurlencode($next);
            }
        }
        return Response::redirect($target);
    }

    private function dispatch(Route $route, Request $request): Response
    {
        $handler = $route->handler;
        if ($handler instanceof Closure) {
            $result = $handler($request);
        } else {
            [$class, $method] = $handler;
            $controller = $this->container->get($class);
            if (!method_exists($controller, $method)) {
                throw new LogicException("A rota {$route->method} {$route->path} aponta para {$class}::{$method}, que não existe.");
            }
            $result = $controller->{$method}($request);
        }
        if (!$result instanceof Response) {
            throw new LogicException("O manipulador da rota {$route->method} {$route->path} deve devolver " . Response::class . '.');
        }
        return $result;
    }

    // ------------------------------------------------------------------ erros

    private function errorResponse(Throwable $error, ?Route $route): Response
    {
        $request = $this->currentRequest();
        $status = 500;
        $code = 'server_error';
        $message = self::GENERIC_ERROR;
        $headers = [];
        $fields = [];

        if ($error instanceof HttpException) {
            $status = $error->status();
            $code = $error->errorCode();
            $headers = $error->headers();
            $message = $error->getMessage() !== '' ? $error->getMessage() : (self::ERROR_TITLES[$status] ?? 'Erro');
            if ($error instanceof ValidationException) {
                $fields = $error->errors();
            }
        } elseif ($error instanceof ConnectionException) {
            $this->log($error);
            $status = 503;
            $code = 'service_unavailable';
            $message = 'O banco de dados está indisponível no momento. Tente novamente em instantes.';
        } else {
            $this->log($error);
            if (Config::env() === 'development') {
                $message = sprintf('%s: %s (%s:%d)', $error::class, $error->getMessage(), basename($error->getFile()), $error->getLine());
            }
        }

        $path = $request->path();
        if (($route !== null && $route->api) || $request->wantsJson() || $path === '/api' || str_starts_with($path, '/api/')) {
            return Response::jsonError($code, $message, $status, $fields, $headers);
        }
        return $this->errorPage($status, $message, $headers, $route, $error instanceof ConnectionException);
    }

    /** @param array<string, string> $headers */
    private function errorPage(int $status, string $message, array $headers, ?Route $route, bool $databaseDown): Response
    {
        $title = self::ERROR_TITLES[$status] ?? 'Erro';
        $user = $this->currentRequest()->user();
        if ($user === null && !$databaseDown && ($route === null || $route->access !== Router::ACCESS_PUBLIC) && $this->session->userId() !== null) {
            try {
                $this->bind($this->currentRequest()->withUser($this->resolveUser()));
                $user = $this->currentRequest()->user();
            } catch (Throwable) {
                $user = null;
            }
        }
        $layouts = $route?->access === Router::ACCESS_PUBLIC ? ['layouts/public'] : ($user !== null ? ['layouts/app', 'layouts/auth'] : ['layouts/auth']);
        foreach ($layouts as $layout) {
            try {
                $html = $this->view()->render('errors/error', ['status' => $status, 'title' => $title, 'message' => $message], $layout);
                return Response::html($html, $status, $headers);
            } catch (Throwable $viewError) {
                $this->log($viewError);
            }
        }
        return Response::html(self::fallbackPage($status, $title, $message), $status, $headers);
    }

    private function log(Throwable $error): void
    {
        error_log(sprintf("[SAA] %s: %s in %s:%d\n%s", $error::class, $error->getMessage(), $error->getFile(), $error->getLine(), $error->getTraceAsString()));
    }

    /** Página mínima (sem estilos nem scripts) usada quando nem o template de erro pode ser renderizado. */
    private static function fallbackPage(int $status, string $title, string $message): string
    {
        $title = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . ' · SAA</title></head><body><main><h1>' . $title . '</h1><p>' . $message . '</p><p><a href="/">Voltar ao início</a></p></main></body></html>'
            . "\n<!-- erro {$status} -->";
    }

    // ------------------------------------------------------------------ internos

    private function bind(Request $request): void
    {
        $this->request = $request;
        $this->container->instance(Request::class, $request);
    }

    private function currentRequest(): Request
    {
        return $this->request ?? throw new LogicException('Nenhuma requisição em andamento.');
    }

    private static function loadRoutes(Router $router, string $directory): void
    {
        $files = glob(rtrim($directory, '/\\') . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            $register = require $file;
            if (!is_callable($register)) {
                throw new RuntimeException("O arquivo de rotas {$file} deve retornar uma função (Router \$router): void.");
            }
            $register($router);
        }
    }

    /** Erros nunca vão para a página: ficam no log. Fora de produção, avisos e notices viram exceções (bugs aparecem logo). */
    private static function configureRuntime(): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);
        mb_internal_encoding('UTF-8');
        date_default_timezone_set('UTC');

        if (Config::env() !== 'production') {
            set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
                if ((error_reporting() & $severity) === 0) {
                    return false;
                }
                if (in_array($severity, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_USER_ERROR], true)) {
                    throw new ErrorException($message, 0, $severity, $file, $line);
                }
                return false;
            });
        }
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) || headers_sent()) {
                return;
            }
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            echo self::fallbackPage(500, self::ERROR_TITLES[500], self::GENERIC_ERROR);
        });
    }
}
