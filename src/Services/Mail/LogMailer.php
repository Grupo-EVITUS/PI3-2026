<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use Closure;

/**
 * MAIL_MODE=log (desenvolvimento): nada sai da máquina; destinatário, assunto e TEXTO do e-mail vão para o log de
 * erros do PHP (error_log). O texto inclui o link de justificativa — é assim que se testa o fluxo sem servidor de
 * e-mail. Não use este modo em produção: o log passaria a conter links válidos.
 */
final class LogMailer implements MailerInterface
{
    /** @var Closure(string): void */
    private readonly Closure $writer;

    /** @param (Closure(string): void)|null $writer destino das linhas (padrão: error_log; testes capturam o texto) */
    public function __construct(?Closure $writer = null)
    {
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };
    }

    public function send(MailMessage $message): string
    {
        $messageId = bin2hex(random_bytes(16)) . '@saa.log';
        ($this->writer)(sprintf(
            "[SAA] E-mail (MAIL_MODE=log)\nPara: %s\nAssunto: %s\nMessage-ID: <%s>\n\n%s",
            $message->to,
            $message->subject,
            $messageId,
            $message->text
        ));
        return $messageId;
    }
}
