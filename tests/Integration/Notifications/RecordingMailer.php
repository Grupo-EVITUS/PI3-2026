<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Notifications;

use Saa\Services\Mail\MailerInterface;
use Saa\Services\Mail\MailException;
use Saa\Services\Mail\MailMessage;

/** Mailer de teste: guarda as mensagens "enviadas" e pode falhar nas primeiras N tentativas (ou sempre). */
final class RecordingMailer implements MailerInterface
{
    /** @var list<MailMessage> */
    public array $sent = [];
    public int $calls = 0;

    public function __construct(public int $failures = 0, public string $error = '421 4.3.2 Serviço temporariamente indisponível')
    {
    }

    public function send(MailMessage $message): string
    {
        $this->calls++;
        if ($this->failures !== 0) {
            if ($this->failures > 0) {
                $this->failures--;
            }
            throw new MailException('O servidor SMTP recusou DATA: ' . $this->error);
        }
        $this->sent[] = $message;
        return 'msg-' . count($this->sent) . '@teste.local';
    }

    /** Token em claro extraído do link /justify/<token> da última mensagem (ou da informada). */
    public function tokenOf(?MailMessage $message = null): ?string
    {
        $message ??= $this->sent[array_key_last($this->sent) ?? 0] ?? null;
        if ($message === null || preg_match('#/justify/([A-Za-z0-9_-]{43})#', $message->text, $match) !== 1) {
            return null;
        }
        return $match[1];
    }
}
