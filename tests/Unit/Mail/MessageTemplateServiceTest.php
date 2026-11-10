<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Mail;

use MongoDB\Client;
use PHPUnit\Framework\TestCase;
use Saa\Core\Config;
use Saa\Models\MessageTemplateModel;
use Saa\Services\MessageTemplateService;

/** Renderização dos modelos de e-mail (issue #29): lista branca de variáveis, marcadores adiados e escape no HTML. */
final class MessageTemplateServiceTest extends TestCase
{
    private MessageTemplateService $templates;

    protected function setUp(): void
    {
        Config::reset();
        // render()/finalize() não consultam o banco: o cliente nem chega a conectar
        $this->templates = new MessageTemplateService(new MessageTemplateModel((new Client('mongodb://127.0.0.1:1'))->selectDatabase('saa_unit')));
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function testIssue29WhitelistedVariablesAreReplacedAndUnknownOnesBecomeEmpty(): void
    {
        $text = 'Olá, {{guardian_name}}. {{ student_name }} ({{class_name}}) faltou em {{absence_date}}.{{password}}{{_SERVER}}{{Student_Name}}';

        $rendered = $this->templates->render($text, [
            'guardian_name' => 'Carlos',
            'student_name' => 'Ana',
            'class_name' => '6º Ano A',
            'absence_date' => '09/10/2026',
            'password' => 'nunca-aparece',
        ]);

        self::assertSame('Olá, Carlos. Ana (6º Ano A) faltou em 09/10/2026.Ana', $rendered);
        self::assertStringNotContainsString('nunca-aparece', $rendered, 'variável fora da lista branca não é substituída');
    }

    public function testIssue29ValuesAreReplacedInASinglePassAndCannotInjectOtherVariables(): void
    {
        $rendered = $this->templates->render('Obs.: {{note}} | {{guardian_name}}', [
            'note' => 'veja {{guardian_name}} e {{justification_link}}',
            'guardian_name' => 'Carlos',
        ]);

        self::assertSame('Obs.: veja {guardian_name} e {justification_link} | Carlos', $rendered);
    }

    public function testIssue29LinkAndExpiryAreDeferredMarkersUntilTheMessageIsSent(): void
    {
        $stored = $this->templates->render('Link: {{ justification_link }} até {{expires_at}} — {{student_name}}', ['student_name' => 'Ana']);
        self::assertSame('Link: {{justification_link}} até {{expires_at}} — Ana', $stored);

        $sent = $this->templates->finalize($stored . ' {{student_name}}', [
            'justification_link' => 'https://saa.escola/justify/abc',
            'expires_at' => '08/11/2026',
        ]);
        self::assertSame('Link: https://saa.escola/justify/abc até 08/11/2026 — Ana {{student_name}}', $sent, 'finalize só troca os marcadores adiados');
    }

    public function testIssue29ControlCharactersAreRemovedFromValues(): void
    {
        $rendered = $this->templates->render('{{guardian_name}}', ['guardian_name' => "Car\x00los\x1B\r\nSilva"]);

        self::assertSame("Carlos\nSilva", $rendered);
    }

    public function testIssue29HtmlVersionEscapesContentAndLinksOnlyRealUrls(): void
    {
        $html = MessageTemplateService::toHtml("Olá, <script>alert(1)</script> & \"amigos\".\n\nLink: https://saa.escola/justify/abc_DEF-123", 'Aviso <b>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;amigos&quot;', $html);
        self::assertStringContainsString('<a href="https://saa.escola/justify/abc_DEF-123">https://saa.escola/justify/abc_DEF-123</a>', $html);
        self::assertStringContainsString('<title>Aviso &lt;b&gt;</title>', $html);
        self::assertSame(2, substr_count($html, '<p>'), 'linhas em branco separam parágrafos');
    }

    public function testIssue29SchoolNameComesFromConfigurationWithAPoliteDefault(): void
    {
        Config::set('APP_SCHOOL_NAME', null); // ignora um eventual .env local
        self::assertSame('Equipe escolar', MessageTemplateService::schoolName());

        Config::set('APP_SCHOOL_NAME', 'E.E. Prof. Evitus');
        self::assertSame('E.E. Prof. Evitus', MessageTemplateService::schoolName());
    }
}
