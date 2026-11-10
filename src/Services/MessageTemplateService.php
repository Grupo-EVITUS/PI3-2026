<?php
declare(strict_types=1);

namespace Saa\Services;

use Saa\Core\Config;
use Saa\Database\DefaultData;
use Saa\Models\MessageTemplateModel;
use Saa\Services\Mail\MimeBuilder;

/**
 * Textos de e-mail a partir de message_templates, com variáveis {{nome}} de uma LISTA BRANCA.
 *
 * - Variáveis aceitas: guardian_name, student_name, class_name, absence_date (dd/mm/aaaa), note, justification_link,
 *   expires_at (dd/mm/aaaa) e school_name (APP_SCHOOL_NAME; padrão "Equipe escolar"). Qualquer outra fica vazia.
 * - Substituição em UMA passada: um valor que contenha "{{...}}" nunca é interpretado de novo.
 * - justification_link e expires_at são ADIADAS: ao criar a notificação o texto gravado guarda os marcadores
 *   literais {{justification_link}} / {{expires_at}}; o link real (token novo) só entra no e-mail enviado (finalize()).
 * - A versão HTML escapa todo o conteúdo (toHtml()).
 */
final class MessageTemplateService
{
    /** @var list<string> */
    public const VARIABLES = [
        'guardian_name', 'student_name', 'class_name', 'absence_date', 'note', 'justification_link', 'expires_at', 'school_name',
    ];
    /** @var list<string> */
    public const DEFERRED = ['justification_link', 'expires_at'];
    public const LINK_MARKER = '{{justification_link}}';
    public const DEFAULT_SCHOOL_NAME = 'Equipe escolar';

    private const PLACEHOLDER = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';
    private const SUBJECT_MAX = 255;
    private const BODY_MAX = 10000;

    public function __construct(private readonly MessageTemplateModel $templates)
    {
    }

    /**
     * Modelo ativo pelo código; sem modelo no banco, usa o texto padrão (DefaultData) para que o envio nunca pare.
     *
     * @return array{subject: string, body: string}
     */
    public function template(string $code = DefaultData::ABSENCE_NOTICE): array
    {
        $template = $this->templates->findActiveByCode($code);
        if (is_array($template) && is_string($template['subject'] ?? null) && is_string($template['body'] ?? null)) {
            return ['subject' => $template['subject'], 'body' => $template['body']];
        }
        return ['subject' => DefaultData::ABSENCE_NOTICE_SUBJECT, 'body' => DefaultData::ABSENCE_NOTICE_BODY];
    }

    /**
     * Aviso de ausência pronto para GRAVAR (com os marcadores adiados). O corpo sempre contém {{justification_link}}.
     *
     * @param array<string, string|null> $values valores das variáveis (fora da lista branca são ignorados)
     * @return array{subject: string, body: string}
     */
    public function renderAbsenceNotice(array $values, string $code = DefaultData::ABSENCE_NOTICE): array
    {
        $values['school_name'] ??= self::schoolName();
        $template = $this->template($code);
        $subject = $this->subject($this->render($template['subject'], $values));
        $body = $this->body($this->render($template['body'], $values));
        if (!str_contains($body, self::LINK_MARKER)) {
            $body .= "\n\nLink para justificar a ausência: " . self::LINK_MARKER;
        }
        return ['subject' => $subject, 'body' => mb_substr($body, 0, self::BODY_MAX)];
    }

    /**
     * Substitui as variáveis da lista branca. Variáveis adiadas sem valor viram o marcador canônico; desconhecidas
     * ou sem valor ficam vazias.
     *
     * @param array<string, string|null> $values
     */
    public function render(string $text, array $values, bool $keepDeferred = true): string
    {
        return (string)preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values, $keepDeferred): string {
            $name = strtolower($match[1]);
            if (!in_array($name, self::VARIABLES, true)) {
                return '';
            }
            $value = $values[$name] ?? null;
            if ($value === null && $keepDeferred && in_array($name, self::DEFERRED, true)) {
                return '{{' . $name . '}}';
            }
            return self::clean((string)($value ?? ''));
        }, $text);
    }

    /**
     * Completa SOMENTE as variáveis adiadas (link e validade) no texto gravado — usado no momento do envio.
     *
     * @param array{justification_link: string, expires_at: string} $values
     */
    public function finalize(string $text, array $values): string
    {
        return (string)preg_replace_callback(self::PLACEHOLDER, static function (array $match) use ($values): string {
            $name = strtolower($match[1]);
            return in_array($name, self::DEFERRED, true) ? self::clean((string)($values[$name] ?? '')) : $match[0];
        }, $text);
    }

    /** Versão HTML do e-mail: todo o texto escapado, links clicáveis, parágrafos. */
    public static function toHtml(string $text, string $title = ''): string
    {
        return MimeBuilder::htmlFromText($text, $title);
    }

    public static function schoolName(): string
    {
        $name = trim(Config::string('APP_SCHOOL_NAME'));
        return $name !== '' ? self::clean($name) : self::DEFAULT_SCHOOL_NAME;
    }

    // ------------------------------------------------------------------ internos

    /** Valor seguro: sem caracteres de controle (exceto quebra de linha) e sem chaves duplas. */
    private static function clean(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $value);
        return str_replace(['{{', '}}'], ['{', '}'], $value);
    }

    private function subject(string $text): string
    {
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));
        $text = mb_substr($text, 0, self::SUBJECT_MAX);
        return $text !== '' ? $text : 'Comunicado de ausência escolar';
    }

    private function body(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string)preg_replace('/[ \t]+\n/', "\n", $text);
        $text = (string)preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }
}
