<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use DateTimeInterface;
use InvalidArgumentException;
use Saa\Core\Clock;

/**
 * Monta a mensagem RFC 5322 / MIME (multipart/alternative: texto + HTML, UTF-8, quoted-printable) usada pelo
 * FileMailer (.eml) e pelo SmtpMailer (comando DATA). Cabeçalhos com acentos usam encoded-words =?UTF-8?B?...?=.
 * Todas as linhas terminam em CRLF. O dot-stuffing do SMTP NÃO é feito aqui (é responsabilidade do SmtpMailer).
 */
final class MimeBuilder
{
    /**
     * @return array{message: string, message_id: string} mensagem completa e Message-ID (sem < >)
     * @throws InvalidArgumentException remetente inválido
     */
    public static function build(MailMessage $message, string $from, string $fromName = '', ?DateTimeInterface $date = null): array
    {
        if (!MailMessage::isValidAddress($from)) {
            throw new InvalidArgumentException('Remetente de e-mail inválido (MAIL_FROM).');
        }
        if (MailMessage::hasControlCharacters($fromName)) {
            throw new InvalidArgumentException('O nome do remetente (MAIL_FROM_NAME) contém caracteres de controle.');
        }
        $messageId = self::messageId($from);
        $boundary = 'saa_' . bin2hex(random_bytes(12));
        $html = $message->html ?? self::htmlFromText($message->text, $message->subject);

        $headers = [
            'Date: ' . ($date ?? Clock::now())->format(DateTimeInterface::RFC2822),
            'From: ' . self::address($from, $fromName),
            'To: ' . self::address($message->to, $message->toName),
            'Subject: ' . self::encodeHeader($message->subject),
            'Message-ID: <' . $messageId . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
        ];
        $lines = [
            ...$headers,
            '',
            'Esta é uma mensagem em formato MIME.',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            self::quotedPrintable($message->text),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            self::quotedPrintable($html),
            '--' . $boundary . '--',
            '',
        ];
        return ['message' => implode("\r\n", $lines), 'message_id' => $messageId];
    }

    /** Cabeçalho com texto livre: ASCII imprimível fica como está; o resto vira encoded-words UTF-8 (≤ 75 caracteres cada). */
    public static function encodeHeader(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $text) === 1 && strlen($text) <= 900) {
            return $text;
        }
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if (strlen($chunk . $char) > 39) { // 39 bytes → 52 em base64: linha "Subject: =?UTF-8?B?...?=" com ≤ 78
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '' || $words === []) {
            $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        return implode("\r\n ", $words);
    }

    /** HTML simples a partir do texto: tudo escapado, links http(s) clicáveis, parágrafos por linha em branco. */
    public static function htmlFromText(string $text, string $title = ''): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $block) {
            $escaped = htmlspecialchars($block, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $linked = preg_replace('#https?://[^\s<>"\']+#', '<a href="$0">$0</a>', $escaped) ?? $escaped;
            $paragraphs[] = '<p>' . str_replace("\n", "<br>\n", $linked) . '</p>';
        }
        return "<!doctype html>\n<html lang=\"pt-BR\">\n<head>\n<meta charset=\"utf-8\">\n<title>"
            . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</title>\n</head>\n<body>\n"
            . implode("\n", $paragraphs) . "\n</body>\n</html>\n";
    }

    private static function address(string $email, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '<' . $email . '>';
        }
        $display = preg_match('/^[A-Za-z0-9 .\-]+$/', $name) === 1 ? '"' . $name . '"' : self::encodeHeader($name);
        return $display . ' <' . $email . '>';
    }

    private static function quotedPrintable(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return quoted_printable_encode(str_replace("\n", "\r\n", $text));
    }

    private static function messageId(string $from): string
    {
        $domain = strtolower(substr($from, (int)strrpos($from, '@') + 1));
        $domain = preg_replace('/[^a-z0-9.\-]/', '', $domain) ?: 'saa.local';
        return bin2hex(random_bytes(16)) . '@' . $domain;
    }
}
