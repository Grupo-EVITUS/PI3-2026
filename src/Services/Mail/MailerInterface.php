<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

/**
 * Envio de e-mail. Implementações: LogMailer (MAIL_MODE=log), FileMailer (file) e SmtpMailer (smtp); a escolha fica
 * em MailerFactory. Testes injetam um Mailer falso (MailerFactory recebe um MailerInterface pronto).
 */
interface MailerInterface
{
    /**
     * Envia a mensagem e devolve o identificador da mensagem (Message-ID, sem os sinais < >), que é gravado em
     * email_notifications.provider_message_id e usado pelo webhook do provedor.
     *
     * @throws MailException se o envio falhar (a mensagem não contém segredos)
     */
    public function send(MailMessage $message): string;
}
