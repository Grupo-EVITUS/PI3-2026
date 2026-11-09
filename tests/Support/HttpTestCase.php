<?php
declare(strict_types=1);

namespace Saa\Tests\Support;

use RuntimeException;
use Saa\Core\Config;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Models\UserModel;
use Saa\Support\PasswordHasher;

/**
 * Base dos testes de aceitação: inicia o servidor embutido do PHP (php -S, mesmo PHP_BINARY e mesmo ambiente do
 * teste) numa porta livre, com um banco MongoDB exclusivo (saa_test_<hex>, o mesmo do MongoTestCase) e uma pasta
 * de sessões temporária, e espera /health responder. Um servidor por classe de teste; um HttpClient novo
 * (cookie jar vazio) por teste. Variáveis de ambiente do processo (MONGODB_URI, VIEWS_PATH...) são herdadas.
 */
abstract class HttpTestCase extends MongoTestCase
{
    protected const PASSWORD = 'senha-segura-123';

    private static string $baseUrl = '';
    /** @var resource|null */
    private static $process = null;
    private static string $logFile = '';
    private static string $sessionDirectory = '';

    protected HttpClient $http;

    /**
     * Variáveis de ambiente adicionais do servidor (subclasses podem sobrescrever).
     *
     * @return array<string, string>
     */
    protected static function serverEnvironment(): array
    {
        return [];
    }

    /** true = php -S ... -t public router.php (padrão); false = sem -t, como o antigo "composer serve". */
    protected static function serverUsesPublicDocumentRoot(): bool
    {
        return true;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Config::set('PASSWORD_COST', '4');
        try {
            self::startServer();
        } catch (\Throwable $error) {
            parent::tearDownAfterClass(); // remove o banco temporário mesmo quando o servidor não sobe
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpClient(self::$baseUrl);
    }

    protected static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    /** Últimas linhas do log do servidor (diagnóstico de falhas). */
    protected static function serverLog(int $lines = 30): string
    {
        $content = self::$logFile !== '' && is_file(self::$logFile) ? (string)file_get_contents(self::$logFile) : '';
        return implode("\n", array_slice(explode("\n", trim($content)), -$lines));
    }

    // ------------------------------------------------------------------ dados e login

    /**
     * Cria um usuário diretamente no banco de teste (a senha padrão é PASSWORD).
     *
     * @return array<string, mixed> documento sem password_hash
     */
    protected function createUser(
        string $email = 'maria@escola.local',
        string $profile = 'ADMIN',
        bool $active = true,
        ?string $password = null,
        string $name = 'Maria Souza',
    ): array {
        $user = (new UserModel(Mongo::database()))->create($name, $email, PasswordHasher::hash($password ?? self::PASSWORD), $profile);
        if (!$active) {
            Mongo::collection(Collections::USERS)->updateOne(['_id' => $user['_id']], ['$set' => ['active' => false]]);
            $user['active'] = false;
        }
        return $user;
    }

    /** Token CSRF do primeiro formulário da página. */
    protected function csrfToken(HttpResponse $page): string
    {
        if (preg_match('/name="csrf_token"\s+value="([a-f0-9]{32,})"/', $page->body, $matches) !== 1) {
            self::fail('A página não contém o campo csrf_token. Corpo: ' . substr($page->text(), 0, 300));
        }
        return $matches[1];
    }

    /** Abre /login (para obter sessão e token) e envia o formulário. */
    protected function login(string $email, ?string $password = null, ?string $next = null, ?HttpClient $client = null): HttpResponse
    {
        $client ??= $this->http;
        $page = $client->get('/login');
        $fields = ['csrf_token' => $this->csrfToken($page), 'email' => $email, 'password' => $password ?? self::PASSWORD];
        if ($next !== null) {
            $fields['next'] = $next;
        }
        return $client->post('/login', $fields);
    }

    /** Faz POST /logout com o token CSRF da página atual (a sessão precisa estar autenticada). */
    protected function logout(?HttpClient $client = null): HttpResponse
    {
        $client ??= $this->http;
        $page = $client->get('/me');
        return $client->post('/logout', ['csrf_token' => $this->csrfToken($page)]);
    }

    // ------------------------------------------------------------------ servidor

    private static function startServer(): void
    {
        self::$sessionDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa_sessions_' . bin2hex(random_bytes(6));
        if (!mkdir(self::$sessionDirectory, 0700, true) && !is_dir(self::$sessionDirectory)) {
            throw new RuntimeException('Não foi possível criar a pasta de sessões de teste.');
        }
        self::$logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa_server_' . bin2hex(random_bytes(6)) . '.log';

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException("Não foi possível reservar uma porta livre: {$errorMessage}");
        }
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        $root = Config::basePath();
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            array_push($command, '-c', $ini);
        }
        array_push($command, '-S', '127.0.0.1:' . $port);
        if (static::serverUsesPublicDocumentRoot()) {
            array_push($command, '-t', $root . DIRECTORY_SEPARATOR . 'public');
        }
        $command[] = $root . DIRECTORY_SEPARATOR . 'router.php';

        $environment = array_merge(getenv(), [
            'APP_ENV' => 'test',
            'MONGODB_DB' => self::databaseName(),
            'SESSION_SAVE_PATH' => self::$sessionDirectory,
            'PASSWORD_COST' => '4',
            'LOGIN_MAX_ATTEMPTS' => '3',
            'LOGIN_WINDOW_MINUTES' => '15',
            'SESSION_COOKIE_SECURE' => 'false',
        ], static::serverEnvironment());

        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            $command,
            [0 => ['file', $nullDevice, 'r'], 1 => ['file', self::$logFile, 'a'], 2 => ['file', self::$logFile, 'a']],
            $pipes,
            $root,
            $environment
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível iniciar o servidor embutido do PHP.');
        }
        self::$process = $process;

        $client = new HttpClient(self::$baseUrl);
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $log = self::serverLog();
                self::stopServer();
                self::fail("O servidor embutido terminou ao iniciar. Log:\n" . $log);
            }
            try {
                $client->get('/health');
                return;
            } catch (RuntimeException) {
                usleep(100_000);
            }
        }
        $log = self::serverLog();
        self::stopServer();
        self::fail("O servidor embutido não respondeu a /health em 20 s. Log:\n" . $log);
    }

    private static function stopServer(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
        self::$process = null;
        if (self::$sessionDirectory !== '' && is_dir(self::$sessionDirectory)) {
            foreach (glob(self::$sessionDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(self::$sessionDirectory);
        }
        if (self::$logFile !== '' && is_file(self::$logFile)) {
            @unlink(self::$logFile);
        }
        self::$sessionDirectory = '';
        self::$logFile = '';
        self::$baseUrl = '';
    }
}
