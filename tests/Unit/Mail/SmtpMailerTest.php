<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use Saa\Services\Mail\MailException;
use Saa\Services\Mail\MailMessage;
use Saa\Services\Mail\SmtpMailer;
use Saa\Tests\Support\FakeSmtpServer;

/**
 * SmtpMailer contra um servidor SMTP FALSO local (tests/Support/fake_smtp_server.php, só 127.0.0.1) — issue #29:
 * sequência de comandos, autenticação, cabeçalhos, codificação UTF-8, multipart, dot-stuffing e falhas.
 */
final class SmtpMailerTest extends TestCase
{
    private const USER = 'saa-envio';
    private const PASSWORD = 'senha-smtp-super-secreta';

    private ?FakeSmtpServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function mailer(string $encryption = 'none', string $username = self::USER, string $password = self::PASSWORD, ?int $port = null): SmtpMailer
    {
        return new SmtpMailer('127.0.0.1', $port ?? $this->server?->port() ?? 1, $encryption, $username, $password, 'saa@escola.local', 'SAA - Ausências', 5, 'saa.test');
    }

    public function testIssue29SendsAMultipartUtf8MessageWithAuthLoginAndDotStuffing(): void
    {
        $this->server = FakeSmtpServer::start(['auth' => ['user' => self::USER, 'pass' => self::PASSWORD]]);
        $text = "Olá, Carlos.\nAna Beatriz faltou em 09/10/2026.\n.Esta linha começa com ponto\n.\nFim.";
        $message = new MailMessage('carlos@exemplo.com', 'Comunicado de ausência — Ana', $text);

        $messageId = $this->mailer()->send($message);

        $session = $this->server->sessions()[0] ?? self::fail('o servidor não registrou a sessão');
        $verbs = array_map(static fn (string $command): string => strtoupper(strtok($command, ' :') ?: ''), $session['commands']);
        self::assertSame(['EHLO', 'AUTH', 'MAIL', 'RCPT', 'DATA', 'QUIT'], $verbs);
        self::assertSame('EHLO saa.test', $session['commands'][0]);
        self::assertSame('MAIL FROM:<saa@escola.local>', $session['commands'][2]);
        self::assertSame('RCPT TO:<carlos@exemplo.com>', $session['commands'][3]);
        self::assertSame(['user' => self::USER, 'pass' => self::PASSWORD], $session['auth']);

        $raw = (string)$session['data'];
        self::assertStringContainsString("\r\n..Esta linha come=C3=A7a com ponto\r\n", $raw, 'linha que começa com "." é dobrada (dot-stuffing)');
        self::assertStringContainsString("\r\n..\r\n", $raw, 'um "." sozinho não encerra o DATA antes da hora');
        $unstuffed = (string)preg_replace('/^\.\./m', '.', $raw);
        [$head, $body] = explode("\r\n\r\n", $unstuffed, 2);
        $headers = MailersTest::decodeHeaders($head);
        self::assertSame('Comunicado de ausência — Ana', $headers['Subject']);
        self::assertStringStartsWith('=?UTF-8?B?', (string)preg_replace('/^.*\r\nSubject: /s', '', $head), 'assunto com acento em =?UTF-8?B?');
        self::assertSame('<carlos@exemplo.com>', $headers['To']);
        self::assertSame('SAA - Ausências <saa@escola.local>', $headers['From']);
        self::assertSame('<' . $messageId . '>', $headers['Message-ID']);
        self::assertStringStartsWith('multipart/alternative', $headers['Content-Type']);
        preg_match('/text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n(.*?)\r\n--saa_/s', $body, $plain);
        self::assertSame(str_replace("\n", "\r\n", $text), quoted_printable_decode($plain[1] ?? ''));
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $body);
    }

    public function testIssue29WithoutUsernameTheMailerDoesNotAuthenticate(): void
    {
        $this->server = FakeSmtpServer::start();

        $this->mailer(username: '', password: '')->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));

        $commands = $this->server->sessions()[0]['commands'] ?? [];
        self::assertNotContains('AUTH LOGIN', $commands);
        self::assertContains('RCPT TO:<carlos@exemplo.com>', $commands);
    }

    public function testIssue29RefusedRecipientFailsWithTheServerReplyAndNoSecrets(): void
    {
        $this->server = FakeSmtpServer::start(['reject' => ['RCPT' => '550 5.1.1 Caixa postal inexistente']]);

        try {
            $this->mailer()->send(new MailMessage('ninguem@exemplo.com', 'Aviso', 'Texto confidencial do corpo'));
            self::fail('o envio deveria falhar');
        } catch (MailException $error) {
            self::assertStringContainsString('RCPT TO', $error->getMessage());
            self::assertStringContainsString('550 5.1.1 Caixa postal inexistente', $error->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $error->getMessage());
            self::assertStringNotContainsString(base64_encode(self::PASSWORD), $error->getMessage());
            self::assertStringNotContainsString('Texto confidencial', $error->getMessage());
        }
        self::assertNotContains('DATA', $this->server->sessions()[0]['commands'] ?? [], 'sem destinatário aceito, nada é enviado');
    }

    public function testIssue29WrongCredentialsFailWithoutLeakingThePassword(): void
    {
        $this->server = FakeSmtpServer::start(['auth' => ['user' => self::USER, 'pass' => 'outra-senha']]);

        try {
            $this->mailer()->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));
            self::fail('o envio deveria falhar');
        } catch (MailException $error) {
            self::assertStringContainsString('535', $error->getMessage());
            self::assertStringNotContainsString(self::PASSWORD, $error->getMessage());
            self::assertStringNotContainsString(base64_encode(self::PASSWORD), $error->getMessage());
        }
    }

    public function testIssue29StartTlsRefusedStopsBeforeSendingCredentials(): void
    {
        $this->server = FakeSmtpServer::start(['starttls' => true]);

        try {
            $this->mailer('starttls')->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));
            self::fail('o envio deveria falhar');
        } catch (MailException $error) {
            self::assertStringContainsString('STARTTLS', $error->getMessage());
        }
        $commands = $this->server->sessions()[0]['commands'] ?? [];
        self::assertContains('STARTTLS', $commands);
        self::assertNotContains('AUTH LOGIN', $commands, 'a senha nunca vai em texto puro');
    }

    public function testIssue29StartTlsIsRequiredWhenTheServerDoesNotOfferIt(): void
    {
        $this->server = FakeSmtpServer::start(['starttls' => false]);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('não oferece STARTTLS');
        $this->mailer('starttls')->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));
    }

    public function testIssue29ConnectionFailureAndIncompleteConfigurationBecomeMailExceptions(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket); // porta livre e fechada: conexão recusada
        $port = (int)substr($name, (int)strrpos($name, ':') + 1);

        try {
            $this->mailer(port: $port)->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));
            self::fail('a conexão deveria falhar');
        } catch (MailException $error) {
            self::assertStringContainsString('Não foi possível conectar ao servidor SMTP', $error->getMessage());
        }

        $invalid = [
            new SmtpMailer('', 587, 'starttls', '', '', 'saa@escola.local'),
            new SmtpMailer('smtp.escola.local', 587, 'tls-mágico', '', '', 'saa@escola.local'),
            new SmtpMailer('smtp.escola.local', 587, 'starttls', '', '', "saa@escola.local\r\nRCPT TO:<x@y.z>"),
            new SmtpMailer("smtp.escola.local\r\nX", 587, 'starttls', '', '', 'saa@escola.local'),
        ];
        foreach ($invalid as $mailer) {
            try {
                $mailer->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));
                self::fail('configuração inválida deveria ser recusada');
            } catch (MailException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testIssue29DotStuffingNormalizesLineEndings(): void
    {
        self::assertSame("a\r\n..b\r\nc\r\n", SmtpMailer::dotStuff("a\n.b\rc"));
        self::assertSame("..\r\n", SmtpMailer::dotStuff('.'));
    }
}
