<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Mail;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Core\Config;
use Saa\Services\Mail\FileMailer;
use Saa\Services\Mail\LogMailer;
use Saa\Services\Mail\MailerFactory;
use Saa\Services\Mail\MailerInterface;
use Saa\Services\Mail\MailException;
use Saa\Services\Mail\MailMessage;
use Saa\Services\Mail\MimeBuilder;
use Saa\Services\Mail\SmtpMailer;

/** Mensagem, montagem MIME, LogMailer, FileMailer e escolha do Mailer por MAIL_MODE (issue #29). */
final class MailersTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        Config::reset();
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa_mail_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($this->directory)) {
            @rmdir($this->directory);
        }
        Config::reset();
    }

    /** @return array<string, array{string, string, string}> */
    public static function headerInjections(): array
    {
        return [
            'CRLF no destinatário' => ["ana@escola.local\r\nBcc: todos@escola.local", 'Aviso', ''],
            'LF no destinatário' => ["ana@escola.local\nBcc: x@y.z", 'Aviso', ''],
            'sinais < > no destinatário' => ['<ana@escola.local>', 'Aviso', ''],
            'destinatário inválido' => ['não-é-email', 'Aviso', ''],
            'CRLF no assunto' => ['ana@escola.local', "Aviso\r\nBcc: todos@escola.local", ''],
            'LF no nome' => ['ana@escola.local', 'Aviso', "Ana\nBcc: x@y.z"],
            'assunto vazio' => ['ana@escola.local', '   ', ''],
        ];
    }

    #[DataProvider('headerInjections')]
    public function testIssue29MessageRejectsHeaderInjectionAndInvalidAddresses(string $to, string $subject, string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MailMessage($to, $subject, 'Texto', null, $name);
    }

    public function testIssue29MimeMessageIsMultipartUtf8WithEncodedHeaders(): void
    {
        $subject = 'Comunicado de ausência: Ana Beatriz (09/10/2026) — atenção, este assunto é longo o bastante para dobrar';
        $text = "Olá, Carlos.\nLinha com acentuação: ção, é, ü.\n.linha que começa com ponto";
        $built = MimeBuilder::build(new MailMessage('carlos@exemplo.com', $subject, $text, null, 'Carlos Souza'), 'saa@escola.local', 'SAA - Ausências');
        $message = $built['message'];

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}@escola\.local$/', $built['message_id']);
        self::assertStringNotContainsString("\n", str_replace("\r\n", '', $message), 'todas as linhas terminam em CRLF');
        [$head, $rest] = explode("\r\n\r\n", $message, 2);
        $headers = self::decodeHeaders($head);
        self::assertSame($subject, $headers['Subject']);
        self::assertStringContainsString('=?UTF-8?B?', $head, 'acentos no assunto vão como encoded-word');
        self::assertSame('SAA - Ausências <saa@escola.local>', $headers['From']);
        self::assertSame('"Carlos Souza" <carlos@exemplo.com>', $headers['To']);
        self::assertSame('<' . $built['message_id'] . '>', $headers['Message-ID']);
        self::assertStringStartsWith('multipart/alternative; boundary="saa_', $headers['Content-Type']);
        foreach (explode("\r\n", $head) as $line) {
            self::assertLessThanOrEqual(78, strlen($line), 'cabeçalhos dobrados em linhas curtas');
        }

        self::assertSame(2, substr_count($rest, 'Content-Transfer-Encoding: quoted-printable'));
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $rest);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $rest);
        preg_match('/text\/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n(.*?)\r\n--saa_/s', $rest, $plain);
        self::assertSame(str_replace("\n", "\r\n", $text), quoted_printable_decode($plain[1]));
    }

    /**
     * Desdobra os cabeçalhos e decodifica encoded-words =?UTF-8?B?...?= (espaço entre palavras codificadas é ignorado).
     *
     * @return array<string, string>
     */
    public static function decodeHeaders(string $head): array
    {
        $unfolded = (string)preg_replace('/\r\n[ \t]+/', ' ', $head);
        $headers = [];
        foreach (explode("\r\n", $unfolded) as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $headers[$name] = (string)preg_replace_callback(
                '/=\?UTF-8\?B\?([A-Za-z0-9+\/=]*)\?=(?:\s+(?==\?UTF-8\?B\?))?/i',
                static fn (array $match): string => (string)base64_decode($match[1], true),
                $value
            );
        }
        return $headers;
    }

    public function testIssue29HtmlPartIsGeneratedFromTheTextAndEscaped(): void
    {
        $built = MimeBuilder::build(new MailMessage('carlos@exemplo.com', 'Aviso', 'Nome: <b>Ana</b> & cia'), 'saa@escola.local');
        $decoded = quoted_printable_decode($built['message']);

        self::assertStringContainsString('Nome: &lt;b&gt;Ana&lt;/b&gt; &amp; cia', $decoded);
        self::assertStringNotContainsString('<b>Ana</b></p>', $decoded);
    }

    public function testIssue29InvalidSenderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        MimeBuilder::build(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'), "saa@escola.local\r\nBcc: x@y.z");
    }

    public function testIssue29LogMailerWritesRecipientSubjectAndTextToTheLog(): void
    {
        $lines = [];
        $mailer = new LogMailer(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $id = $mailer->send(new MailMessage('carlos@exemplo.com', 'Comunicado de ausência', "Olá.\nLink: http://127.0.0.1:8000/justify/abc"));

        self::assertCount(1, $lines);
        self::assertStringContainsString('Para: carlos@exemplo.com', $lines[0]);
        self::assertStringContainsString('Assunto: Comunicado de ausência', $lines[0]);
        self::assertStringContainsString('Link: http://127.0.0.1:8000/justify/abc', $lines[0]);
        self::assertStringContainsString('<' . $id . '>', $lines[0]);
    }

    public function testIssue29FileMailerWritesOneEmlFilePerMessage(): void
    {
        $mailer = new FileMailer($this->directory, 'saa@escola.local', 'SAA');

        $first = $mailer->send(new MailMessage('carlos@exemplo.com', 'Aviso 1', 'Primeira mensagem'));
        $second = $mailer->send(new MailMessage('maria@exemplo.com', 'Aviso 2', 'Segunda mensagem'));

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.eml') ?: [];
        self::assertCount(2, $files);
        self::assertNotSame($first, $second);
        $contents = implode("\n", array_map(static fn (string $file): string => (string)file_get_contents($file), $files));
        self::assertStringContainsString('To: <carlos@exemplo.com>', $contents);
        self::assertStringContainsString('To: <maria@exemplo.com>', $contents);
        self::assertStringContainsString('Message-ID: <' . $first . '>', $contents);
    }

    public function testIssue29FileMailerFailsWithMailExceptionWhenTheFolderCannotBeUsed(): void
    {
        file_put_contents($this->directory, 'um arquivo no lugar da pasta');
        try {
            $this->expectException(MailException::class);
            (new FileMailer($this->directory, 'saa@escola.local'))->send(new MailMessage('carlos@exemplo.com', 'Aviso', 'Texto'));
        } finally {
            @unlink($this->directory);
        }
    }

    public function testIssue29FactoryChoosesTheMailerByMailModeAndPrefersAnInjectedOne(): void
    {
        Config::set('MAIL_MODE', 'log');
        self::assertInstanceOf(LogMailer::class, (new MailerFactory())->mailer());
        Config::set('MAIL_MODE', 'file');
        Config::set('MAIL_FILE_DIR', $this->directory);
        self::assertInstanceOf(FileMailer::class, (new MailerFactory())->mailer());
        Config::set('MAIL_MODE', 'SMTP');
        self::assertInstanceOf(SmtpMailer::class, (new MailerFactory())->mailer());

        $fake = new class implements MailerInterface {
            public function send(MailMessage $message): string
            {
                return 'fake';
            }
        };
        self::assertSame($fake, (new MailerFactory($fake))->mailer());

        Config::set('MAIL_MODE', 'pombo-correio');
        $this->expectException(InvalidArgumentException::class);
        (new MailerFactory())->mailer();
    }
}
