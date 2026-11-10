<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use RuntimeException;

/**
 * Falha ao enviar um e-mail (conexão, resposta recusada pelo servidor, configuração incompleta...).
 * A mensagem é gravada em email_notifications.last_error: NUNCA inclua senhas, tokens ou o corpo do e-mail.
 */
final class MailException extends RuntimeException
{
}
