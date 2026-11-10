<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * MAIL_MODE=smtp: cliente SMTP mínimo (porte do MVP anterior) sem bibliotecas externas.
 *
 *   conexão (tcp:// ou ssl://) → 220 → EHLO → [STARTTLS → EHLO] → [AUTH LOGIN] → MAIL FROM → RCPT TO → DATA → QUIT
 *
 * - SMTP_ENCRYPTION: starttls (padrão; exige que o servidor ofereça STARTTLS — credenciais nunca vão em texto puro),
 *   ssl (TLS implícito, porta 465) ou none (apenas redes confiáveis / testes).
 * - Mensagem multipart texto + HTML (MimeBuilder), assunto em =?UTF-8?B?...?=, dot-stuffing das linhas que começam com ".".
 * - Tempo limite em conexão e em cada leitura (SMTP_TIMEOUT, padrão 15 s).
 * - Remetente e destinatário validados (sem CR/LF, sem < >) antes de virarem comandos: não há injeção de cabeçalho/comando.
 * - Erros viram MailException com a resposta do servidor resumida — nunca com usuário, senha ou corpo do e-mail.
 */
final class SmtpMailer implements MailerInterface
{
    public const ENCRYPTIONS = ['starttls', 'ssl', 'none'];

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        #[SensitiveParameter] private readonly string $password,
        private readonly string $from,
        private readonly string $fromName = '',
        private readonly int $timeoutSeconds = 15,
        private readonly string $heloName = 'localhost',
    ) {
    }

    public function send(MailMessage $message): string
    {
        $this->assertConfigured();
        try {
            $built = MimeBuilder::build($message, $this->from, $this->fromName);
        } catch (InvalidArgumentException $error) {
            throw new MailException($error->getMessage(), 0, $error);
        }

        $this->connect();
        try {
            $this->expect('saudação', [220]);
            $features = $this->command('EHLO ' . $this->heloName, [250], 'EHLO');
            if ($this->encryption === 'starttls') {
                if (preg_match('/^250[ -]STARTTLS\b/mi', $features) !== 1) {
                    throw new MailException('O servidor SMTP não oferece STARTTLS. Use SMTP_ENCRYPTION=ssl ou verifique SMTP_HOST/SMTP_PORT.');
                }
                $this->command('STARTTLS', [220], 'STARTTLS');
                $this->enableTls();
                $this->command('EHLO ' . $this->heloName, [250], 'EHLO');
            }
            if ($this->username !== '') {
                $this->command('AUTH LOGIN', [334], 'AUTH LOGIN');
                $this->command(base64_encode($this->username), [334], 'AUTH (usuário)');
                $this->command(base64_encode($this->password), [235], 'AUTH (senha)');
            }
            $this->command('MAIL FROM:<' . $this->from . '>', [250], 'MAIL FROM');
            $this->command('RCPT TO:<' . $message->to . '>', [250, 251], 'RCPT TO');
            $this->command('DATA', [354], 'DATA');
            $this->write(self::dotStuff($built['message']) . ".\r\n");
            $this->expect('fim do DATA', [250]);
            try {
                $this->command('QUIT', [221], 'QUIT');
            } catch (MailException) {
                // a mensagem já foi aceita (250): uma falha no QUIT não desfaz o envio
            }
        } finally {
            $this->disconnect();
        }
        return $built['message_id'];
    }

    /** Dobra o ponto inicial das linhas (RFC 5321, 4.5.2) e garante CRLF no final. */
    public static function dotStuff(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = str_replace("\n", "\r\n", $message);
        $message = (string)preg_replace('/^\./m', '..', $message);
        return str_ends_with($message, "\r\n") ? $message : $message . "\r\n";
    }

    // ------------------------------------------------------------------ internos

    private function assertConfigured(): void
    {
        if ($this->host === '' || preg_match('/^[A-Za-z0-9.\-]+$|^\[[0-9A-Fa-f:.]+\]$/', $this->host) !== 1) {
            throw new MailException('Configure SMTP_HOST com o endereço do servidor de e-mail.');
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new MailException('SMTP_PORT inválida.');
        }
        if (!in_array($this->encryption, self::ENCRYPTIONS, true)) {
            throw new MailException('SMTP_ENCRYPTION deve ser starttls, ssl ou none.');
        }
        if (!MailMessage::isValidAddress($this->from)) {
            throw new MailException('Configure MAIL_FROM com um endereço de e-mail válido.');
        }
        if (MailMessage::hasControlCharacters($this->username . $this->password . $this->heloName)) {
            throw new MailException('SMTP_USERNAME/SMTP_PASSWORD contêm caracteres de controle.');
        }
    }

    private function connect(): void
    {
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $context = stream_context_create(['ssl' => ['peer_name' => $this->host, 'verify_peer' => true, 'verify_peer_name' => true]]);
        $socket = @stream_socket_client($remote, $code, $error, max(1, $this->timeoutSeconds), STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new MailException(sprintf('Não foi possível conectar ao servidor SMTP %s:%d (%s).', $this->host, $this->port, self::clean((string)$error) ?: 'sem resposta'));
        }
        stream_set_timeout($socket, max(1, $this->timeoutSeconds));
        $this->socket = $socket;
    }

    private function enableTls(): void
    {
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        stream_context_set_option($this->socket, 'ssl', 'peer_name', $this->host);
        if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
            throw new MailException('Não foi possível iniciar o TLS com o servidor SMTP (STARTTLS).');
        }
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /** @param list<int> $expected */
    private function command(string $line, array $expected, string $label): string
    {
        $this->write($line . "\r\n");
        return $this->expect($label, $expected);
    }

    private function write(string $data): void
    {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $bytes = @fwrite($this->socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) {
                throw new MailException('A conexão com o servidor SMTP foi interrompida durante o envio.');
            }
            $written += $bytes;
        }
    }

    /**
     * Lê uma resposta (com continuação "250-...") e confere o código.
     *
     * @param list<int> $expected
     */
    private function expect(string $label, array $expected): string
    {
        $response = '';
        do {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                throw new MailException(!empty($meta['timed_out'])
                    ? "Tempo esgotado aguardando o servidor SMTP ({$label})."
                    : "O servidor SMTP encerrou a conexão ({$label}).");
            }
            $response .= $line;
        } while (strlen($line) > 3 && $line[3] === '-' && strlen($response) < 16384);

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            $summary = self::clean(strtok($response, "\r\n") ?: '');
            throw new MailException("O servidor SMTP recusou {$label}: {$summary}");
        }
        return $response;
    }

    /** Resposta do servidor resumida para log/last_error: sem caracteres de controle e com no máximo 200 caracteres. */
    private static function clean(string $text): string
    {
        $text = trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text));
        return mb_strcut($text, 0, 200, 'UTF-8');
    }
}
