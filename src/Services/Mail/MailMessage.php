<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use InvalidArgumentException;

/**
 * Mensagem pronta para envio (imutável): destinatário, assunto, texto puro e, opcionalmente, HTML.
 *
 * Proteção contra injeção de cabeçalho: destinatário, nome e assunto não podem conter CR/LF nem outros caracteres de
 * controle (o construtor recusa). O corpo pode ter várias linhas; quebras são normalizadas para CRLF na montagem MIME.
 */
final class MailMessage
{
    public readonly string $to;
    public readonly string $toName;
    public readonly string $subject;
    public readonly string $text;
    public readonly ?string $html;

    public function __construct(string $to, string $subject, string $text, ?string $html = null, string $toName = '')
    {
        $to = trim($to);
        if (!self::isValidAddress($to)) {
            throw new InvalidArgumentException('Destinatário de e-mail inválido.');
        }
        foreach (['assunto' => $subject, 'nome do destinatário' => $toName] as $label => $value) {
            if (self::hasControlCharacters($value)) {
                throw new InvalidArgumentException("O {$label} do e-mail contém quebra de linha ou caractere de controle.");
            }
        }
        if (trim($subject) === '') {
            throw new InvalidArgumentException('O assunto do e-mail não pode ser vazio.');
        }
        if (!mb_check_encoding($subject . $text . ($html ?? '') . $toName, 'UTF-8')) {
            throw new InvalidArgumentException('O e-mail contém texto que não está em UTF-8.');
        }
        $this->to = $to;
        $this->toName = trim($toName);
        $this->subject = trim($subject);
        $this->text = $text;
        $this->html = $html;
    }

    /** Endereço simples (sem nome), válido e sem caracteres que permitam injetar cabeçalhos ou comandos SMTP. */
    public static function isValidAddress(string $address): bool
    {
        return $address !== ''
            && strlen($address) <= 254
            && !self::hasControlCharacters($address)
            && !str_contains($address, '<') && !str_contains($address, '>')
            && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
