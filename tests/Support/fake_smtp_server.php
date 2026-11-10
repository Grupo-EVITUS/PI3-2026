<?php
declare(strict_types=1);

/**
 * Servidor SMTP FALSO para os testes do SmtpMailer (iniciado por Saa\Tests\Support\FakeSmtpServer).
 * Escuta só em 127.0.0.1 numa porta livre (nenhum acesso à rede externa), atende N conexões e grava a conversa
 * (comandos recebidos e o conteúdo bruto do DATA) em JSON.
 *
 *   php fake_smtp_server.php <arquivo-da-porta> <arquivo-da-transcrição> <opções em JSON base64>
 *
 * Opções: connections (padrão 1), starttls (anuncia STARTTLS e responde 454), auth {user, pass},
 * reject {VERBO: "550 mensagem"} (responde com o código informado em vez do sucesso).
 */

if (PHP_SAPI !== 'cli' || $argc < 4) {
    exit(2);
}
[, $portFile, $transcriptFile, $encoded] = $argv;
$options = json_decode((string)base64_decode($encoded, true), true);
$options = is_array($options) ? $options : [];
$connections = max(1, (int)($options['connections'] ?? 1));
$reject = is_array($options['reject'] ?? null) ? $options['reject'] : [];
$auth = is_array($options['auth'] ?? null) ? $options['auth'] : null;

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "não foi possível abrir a porta: {$errorMessage}\n");
    exit(1);
}
$name = (string)stream_socket_get_name($server, false);
file_put_contents($portFile . '.tmp', substr($name, (int)strrpos($name, ':') + 1));
rename($portFile . '.tmp', $portFile);

$sessions = [];
$send = static function ($client, string $reply): void {
    fwrite($client, $reply . "\r\n");
};
$save = static function () use (&$sessions, $transcriptFile): void {
    file_put_contents($transcriptFile . '.tmp', json_encode($sessions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    rename($transcriptFile . '.tmp', $transcriptFile);
};

for ($i = 0; $i < $connections; $i++) {
    $client = @stream_socket_accept($server, 20);
    if ($client === false) {
        break;
    }
    stream_set_timeout($client, 10);
    $session = ['commands' => [], 'auth' => null, 'data' => null];
    $send($client, '220 fake.smtp ESMTP pronto');
    while (($line = fgets($client, 4096)) !== false) {
        $line = rtrim($line, "\r\n");
        $session['commands'][] = $line;
        $verb = strtoupper((string)preg_replace('/[ :].*$/', '', $line));
        if (isset($reject[$verb])) {
            $send($client, (string)$reject[$verb]);
            if ($verb === 'QUIT') {
                break;
            }
            continue;
        }
        switch ($verb) {
            case 'EHLO':
            case 'HELO':
                $features = ['250-fake.smtp', '250-8BITMIME'];
                if (!empty($options['starttls'])) {
                    $features[] = '250-STARTTLS';
                }
                $features[] = '250 AUTH LOGIN';
                $send($client, implode("\r\n", $features));
                break;
            case 'STARTTLS':
                $send($client, '454 4.7.0 TLS indisponível no servidor falso');
                break;
            case 'AUTH':
                $send($client, '334 VXNlcm5hbWU6');
                $user = base64_decode(rtrim((string)fgets($client, 4096), "\r\n"), true);
                $send($client, '334 UGFzc3dvcmQ6');
                $pass = base64_decode(rtrim((string)fgets($client, 4096), "\r\n"), true);
                $session['auth'] = ['user' => $user, 'pass' => $pass];
                $ok = $auth === null || ($user === ($auth['user'] ?? null) && $pass === ($auth['pass'] ?? null));
                $send($client, $ok ? '235 2.7.0 Autenticado' : '535 5.7.8 Credenciais inválidas');
                break;
            case 'MAIL':
            case 'RCPT':
            case 'RSET':
            case 'NOOP':
                $send($client, '250 2.1.0 Ok');
                break;
            case 'DATA':
                $send($client, '354 Envie a mensagem; termine com <CRLF>.<CRLF>');
                $raw = [];
                while (($dataLine = fgets($client, 4096)) !== false) {
                    $dataLine = rtrim($dataLine, "\r\n");
                    if ($dataLine === '.') {
                        break;
                    }
                    $raw[] = $dataLine;
                }
                $session['data'] = implode("\r\n", $raw);
                $send($client, '250 2.0.0 Ok: queued as FAKE' . ($i + 1));
                break;
            case 'QUIT':
                $send($client, '221 2.0.0 Até logo');
                break 2;
            default:
                $send($client, '500 5.5.2 Comando desconhecido');
        }
    }
    fclose($client);
    $sessions[] = $session;
    $save();
}
$save();
fclose($server);
exit(0);
