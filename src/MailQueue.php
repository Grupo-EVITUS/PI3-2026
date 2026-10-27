<?php
declare(strict_types=1);

function processMailQueue(PDO $pdo, int $limit = 20): int
{
    $processed = 0;
    $staleBefore = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
    $recover = $pdo->prepare(
        "UPDATE mail_queue SET status = 'AGUARDANDO', started_at = NULL "
        . "WHERE status = 'PROCESSANDO' AND started_at < ?"
    );
    $recover->execute([$staleBefore]);
    for ($index = 0; $index < $limit; $index++) {
        $pdo->beginTransaction();
        try {
            $job = $pdo->query(
                "SELECT q.*, c.id AS communication_id FROM mail_queue q "
                . "JOIN communications c ON c.id = q.communication_id "
                . "WHERE q.status = 'AGUARDANDO' ORDER BY q.id LIMIT 1"
            )->fetch();
            if (!$job) {
                $pdo->commit();
                break;
            }
            $claim = $pdo->prepare(
                "UPDATE mail_queue SET status = 'PROCESSANDO', started_at = ? WHERE id = ? AND status = 'AGUARDANDO'"
            );
            $claim->execute([Database::now(), (int)$job['id']]);
            if ($claim->rowCount() !== 1) {
                $pdo->commit();
                continue;
            }
            $pdo->commit();

            try {
                sendQueuedEmail($job);
                $status = 'ENVIADO';
                $queueStatus = 'CONCLUIDO';
                $detail = '';
            } catch (Throwable $error) {
                $status = 'FALHA';
                $queueStatus = 'FALHA';
                $detail = substr($error->getMessage(), 0, 500);
                error_log('Falha ao enviar comunicação ' . $job['communication_id'] . ': ' . $detail);
            }

            $pdo->beginTransaction();
            $updateCommunication = $pdo->prepare(
                'UPDATE communications SET status = ?, sent_at = ?, failure_detail = ? WHERE id = ?'
            );
            $updateCommunication->execute([
                $status,
                $status === 'ENVIADO' ? Database::now() : null,
                $detail !== '' ? $detail : null,
                (int)$job['communication_id'],
            ]);
            $updateJob = $pdo->prepare(
                'UPDATE mail_queue SET status = ?, processed_at = ?, started_at = NULL WHERE id = ?'
            );
            $updateJob->execute([$queueStatus, Database::now(), (int)$job['id']]);
            $pdo->prepare('UPDATE mail_queue SET body = ? WHERE id = ?')->execute(['', (int)$job['id']]);
            audit(
                $pdo,
                null,
                'EMAIL_' . $status,
                'comunicacao',
                (int)$job['communication_id'],
                $detail !== '' ? $detail : $job['recipient']
            );
            $pdo->commit();
            $processed++;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
    return $processed;
}

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
