<?php
declare(strict_types=1);

namespace Saa\Services\Mail;

use InvalidArgumentException;
use Saa\Core\Clock;

/**
 * MAIL_MODE=file: grava cada mensagem como um arquivo .eml (abre em qualquer cliente de e-mail) na pasta
 * MAIL_FILE_DIR (padrão var/mail/, ignorada pelo Git). Útil em homologação e nos testes.
 */
final class FileMailer implements MailerInterface
{
    public function __construct(
        private readonly string $directory,
        private readonly string $from,
        private readonly string $fromName = '',
    ) {
    }

    public function send(MailMessage $message): string
    {
        try {
            $built = MimeBuilder::build($message, $this->from, $this->fromName);
        } catch (InvalidArgumentException $error) {
            throw new MailException($error->getMessage(), 0, $error);
        }
        $directory = rtrim($this->directory, '/\\');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new MailException('Não foi possível criar a pasta de e-mails em arquivo (MAIL_FILE_DIR).');
        }
        $name = Clock::now()->format('Ymd_His') . '_' . substr($built['message_id'], 0, 12) . '.eml';
        if (@file_put_contents($directory . DIRECTORY_SEPARATOR . $name, $built['message'], LOCK_EX) === false) {
            throw new MailException('Não foi possível gravar o e-mail em arquivo (MAIL_FILE_DIR).');
        }
        return $built['message_id'];
    }

    public function directory(): string
    {
        return $this->directory;
    }
}
