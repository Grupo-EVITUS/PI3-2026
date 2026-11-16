<?php
declare(strict_types=1);

// Envio de e-mail usado pelo worker da fila MongoDB (bin/process_mail_queue_mongodb.php).
// MAIL_MODE=log só registra no log do PHP; MAIL_MODE=smtp entrega pelo servidor configurado em SMTP_*.

function sendQueuedEmail(array $job): void
{
    $mode = strtolower(getenv('MAIL_MODE') ?: 'log');
    if ($mode === 'log') {
        error_log(
            "SAA DEV EMAIL\nPara: {$job['recipient']}\nAssunto: {$job['subject']}\n\n{$job['body']}"
        );
        return;
    }
    if ($mode !== 'smtp') {
        throw new RuntimeException("MAIL_MODE deve ser 'log' ou 'smtp'.");
    }
    sendSmtpEmail($job);
}

function sendSmtpEmail(array $job): void
{
    $host = getenv('SMTP_HOST') ?: '';
    $port = (int)(getenv('SMTP_PORT') ?: '587');
    $username = getenv('SMTP_USERNAME') ?: '';
    $password = getenv('SMTP_PASSWORD') ?: '';
    $encryption = strtolower(getenv('SMTP_ENCRYPTION') ?: 'starttls');
    $from = getenv('MAIL_FROM') ?: 'nao-responda@escola.local';
    if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($job['recipient'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Configure SMTP_HOST, MAIL_FROM e um destinatário de e-mail válido.');
    }
    if (!in_array($encryption, ['starttls', 'ssl', 'none'], true)) {
        throw new RuntimeException('SMTP_ENCRYPTION deve ser starttls, ssl ou none.');
    }

    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = stream_socket_client($remote, $errorCode, $errorMessage, 12, STREAM_CLIENT_CONNECT);
    if ($socket === false) {
        throw new RuntimeException('Não foi possível conectar ao servidor SMTP: ' . $errorMessage, $errorCode);
    }
    stream_set_timeout($socket, 12);
    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO localhost', [250]);
        if ($encryption === 'starttls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Não foi possível habilitar TLS para SMTP.');
            }
            smtpCommand($socket, 'EHLO localhost', [250]);
        }
        if ($username !== '') {
            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($username), [334]);
            smtpCommand($socket, base64_encode($password), [235]);
        }

        smtpCommand($socket, 'MAIL FROM:<' . $from . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $job['recipient'] . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);
        $boundary = 'saa_' . bin2hex(random_bytes(12));
        $subject = '=?UTF-8?B?' . base64_encode($job['subject']) . '?=';
        $htmlBody = nl2br(htmlspecialchars($job['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $message = implode("\r\n", [
            'From: <' . $from . '>',
            'To: <' . $job['recipient'] . '>',
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            $job['body'],
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            '',
            '<!doctype html><html lang="pt-BR"><body style="font-family:Arial,sans-serif;line-height:1.6;color:#19333a">'
                . '<p>' . $htmlBody . '</p></body></html>',
            '--' . $boundary . '--',
        ]);
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = str_replace("\n", "\r\n", $message);
        $message = preg_replace('/(?m)^\./', '..', $message);
        fwrite($socket, $message . "\r\n.\r\n");
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpCommand($socket, string $command, array $expected): string
{
    fwrite($socket, $command . "\r\n");
    return smtpExpect($socket, $expected);
}

function smtpExpect($socket, array $expected): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('Conexão SMTP encerrada antes da resposta.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('Resposta SMTP inesperada: ' . trim($response));
    }
    return $response;
}
