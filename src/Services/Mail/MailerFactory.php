<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use InvalidArgumentException;
use Saa\Core\Config;

/**
 * Escolhe o Mailer pelo MAIL_MODE (log | file | smtp). É resolvida por autowiring: se o Container tiver um
 * MailerInterface ligado (testes, outro provedor), ele é usado; senão o Mailer é criado a partir da configuração.
 *
 * Variáveis: MAIL_MODE, MAIL_FROM, MAIL_FROM_NAME, MAIL_FILE_DIR (padrão var/mail), SMTP_HOST, SMTP_PORT,
 * SMTP_USERNAME, SMTP_PASSWORD, SMTP_ENCRYPTION (starttls | ssl | none), SMTP_TIMEOUT (segundos, padrão 15).
 */
final class MailerFactory
{
    public const MODES = ['log', 'file', 'smtp'];

    private ?MailerInterface $created = null;

    public function __construct(private readonly ?MailerInterface $mailer = null)
    {
    }

    /** Mailer em uso (criado uma única vez por instância). */
    public function mailer(): MailerInterface
    {
        return $this->mailer ?? ($this->created ??= self::fromConfig());
    }

    /** @throws InvalidArgumentException MAIL_MODE desconhecido */
    public static function fromConfig(?string $mode = null): MailerInterface
    {
        $mode = strtolower(trim($mode ?? Config::string('MAIL_MODE', 'log')));
        $from = Config::string('MAIL_FROM', 'nao-responda@escola.local');
        $fromName = Config::string('MAIL_FROM_NAME', 'SAA - Sistema de Ausência de Alunos');
        return match ($mode) {
            'log' => new LogMailer(),
            'file' => new FileMailer(Config::string('MAIL_FILE_DIR', Config::basePath('var/mail')), $from, $fromName),
            'smtp' => new SmtpMailer(
                Config::string('SMTP_HOST'),
                Config::int('SMTP_PORT', 587),
                strtolower(Config::string('SMTP_ENCRYPTION', 'starttls')),
                Config::string('SMTP_USERNAME'),
                Config::string('SMTP_PASSWORD'),
                $from,
                $fromName,
                max(1, Config::int('SMTP_TIMEOUT', 15)),
            ),
            default => throw new InvalidArgumentException('MAIL_MODE deve ser log, file ou smtp.'),
        };
    }
}
