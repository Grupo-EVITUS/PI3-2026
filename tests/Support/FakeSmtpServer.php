<?php
declare(strict_types=1);

namespace Saa\Tests\Support;

use RuntimeException;

/**
 * Controla o servidor SMTP falso (tests/Support/fake_smtp_server.php) num processo PHP separado — o SmtpMailer é
 * bloqueante, então cliente e servidor não podem rodar no mesmo processo. Só escuta em 127.0.0.1; nada sai da máquina.
 *
 *   $server = FakeSmtpServer::start(['auth' => ['user' => 'u', 'pass' => 'p']]);
 *   (new SmtpMailer('127.0.0.1', $server->port(), 'none', 'u', 'p', 'saa@escola.local'))->send($message);
 *   $session = $server->sessions()[0];   // ['commands' => [...], 'auth' => [...], 'data' => 'mensagem bruta']
 */
final class FakeSmtpServer
{
    /** @var resource|null */
    private $process;
    private int $port = 0;

    /** @param resource $process */
    private function __construct($process, private readonly string $portFile, private readonly string $transcriptFile, private readonly string $logFile)
    {
        $this->process = $process;
    }

    /** @param array<string, mixed> $options connections, starttls, auth {user, pass}, reject {VERBO: "código texto"} */
    public static function start(array $options = []): self
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa_smtp_' . bin2hex(random_bytes(6));
        $portFile = $base . '.port';
        $transcriptFile = $base . '.json';
        $logFile = $base . '.log';
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini) && $ini !== '') {
            array_push($command, '-c', $ini);
        }
        array_push($command, __DIR__ . DIRECTORY_SEPARATOR . 'fake_smtp_server.php', $portFile, $transcriptFile, base64_encode((string)json_encode($options)));
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open($command, [0 => ['file', $nullDevice, 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Não foi possível iniciar o servidor SMTP falso.');
        }
        $server = new self($process, $portFile, $transcriptFile, $logFile);
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $content = is_file($portFile) ? trim((string)file_get_contents($portFile)) : '';
            if ($content !== '' && ctype_digit($content)) {
                $server->port = (int)$content;
                return $server;
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(50_000);
        }
        $log = is_file($logFile) ? (string)file_get_contents($logFile) : '';
        $server->stop();
        throw new RuntimeException('O servidor SMTP falso não iniciou. ' . $log);
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Sessões concluídas (espera até $expected sessões serem gravadas ou 5 s).
     *
     * @return list<array{commands: list<string>, auth: array{user: string|false, pass: string|false}|null, data: string|null}>
     */
    public function sessions(int $expected = 1): array
    {
        $deadline = microtime(true) + 5.0;
        $sessions = [];
        do {
            if (is_file($this->transcriptFile)) {
                $decoded = json_decode((string)file_get_contents($this->transcriptFile), true);
                $sessions = is_array($decoded) ? $decoded : [];
                if (count($sessions) >= $expected) {
                    break;
                }
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        return $sessions;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
        }
        $this->process = null;
        foreach ([$this->portFile, $this->transcriptFile, $this->logFile, $this->portFile . '.tmp', $this->transcriptFile . '.tmp'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
