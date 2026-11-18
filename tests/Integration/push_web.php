<?php
declare(strict_types=1);

/**
 * Percurso HTTP completo do push, no servidor de teste:
 * convite da coordenação → ativação no "celular" → chamada → worker → serviço de push → aparelho decifra →
 * toque abre a justificativa → resposta registrada. Requer push_service_stub.php em PUSH_STUB_URL.
 */

use Saa\Database\Mongo;
use Saa\Push\WebPush;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/Unit/Push/PushTestSupport.php';
require __DIR__ . '/web_client.php';

$base = rtrim((string)getenv('PUBLIC_BASE_URL'), '/');
$stubUrl = rtrim((string)getenv('PUSH_STUB_URL'), '/');
$stubDir = (string)getenv('PUSH_STUB_DIR');
if ($base === '' || $stubUrl === '' || !is_dir($stubDir)) {
    fwrite(STDERR, "Defina PUBLIC_BASE_URL, PUSH_STUB_URL e PUSH_STUB_DIR.\n");
    exit(1);
}

function webCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('push_web: ' . $message);
    }
}

$db = Mongo::database();
$coord = [];
// Arquivos do PWA servidos com o tipo correto.
[$status, , $headers] = browse($coord, $base . '/sw.js');
webCheck($status === 200 && str_starts_with($headers['content-type'] ?? '', 'text/javascript'), 'sw.js não servido como JavaScript.');
[$status, $manifest, $headers] = browse($coord, $base . '/manifest.webmanifest');
webCheck($status === 200 && str_starts_with($headers['content-type'] ?? '', 'application/manifest+json')
    && json_decode($manifest, true)['start_url'] === '/notificacoes', 'manifesto do PWA inválido.');
webCheck(browse($coord, $base . '/notificacoes')[0] === 200, 'página inicial do app indisponível.');

[, $page] = browse($coord, $base . '/login');
[$status, $page] = browse($coord, $base . '/login', ['email' => getenv('INITIAL_ADMIN_EMAIL'),
    'password' => getenv('INITIAL_ADMIN_PASSWORD'), 'csrf_token' => csrfFrom($page)]);
[$status, $page] = browse($coord, $base . '/cadastros');
webCheck($status === 200, 'login da coordenação falhou.');
[, $page] = browse($coord, $base . '/cadastros/turma', ['name' => 'Turma Push CI', 'grade' => '7º ano',
    'academic_year' => '2026', 'shift' => 'Manhã', 'csrf_token' => csrfFrom($page)]);
preg_match('#<option value="([a-f0-9]{24})">Turma Push CI</option>#', $page, $class);
[, $page] = browse($coord, $base . '/cadastros/aluno', ['name' => 'Aluna Push CI', 'registration_no' => 'PUSH-CI',
    'class_id' => $class[1], 'csrf_token' => csrfFrom($page)]);
preg_match('#value="([a-f0-9]{24})"> Aluna Push CI#', $page, $student);
[, $page] = browse($coord, $base . '/cadastros/responsavel', ['name' => 'Mãe Push CI', 'email' => 'mae.push@example.org',
    'relationship' => 'Mãe', 'student_ids[]' => $student[1], 'csrf_token' => csrfFrom($page)]);
$guardian = $db->guardians->findOne(['email' => 'mae.push@example.org']);
webCheck($guardian !== null && str_contains($page, 'Somente e-mail'), 'responsável não cadastrado.');

// Convite exige CSRF e devolve o link uma única vez.
webCheck(browse($coord, $base . '/cadastros/responsavel/notificacoes', ['id' => (string)$guardian['_id']])[0] === 400,
    'convite sem CSRF aceito.');
[$status, $page] = browse($coord, $base . '/cadastros/responsavel/notificacoes',
    ['id' => (string)$guardian['_id'], 'csrf_token' => csrfFrom($page)]);
webCheck($status === 200 && preg_match('#value="(https?://[^"]+/notificacoes/ativar/([A-Za-z0-9_-]{43}))"#', $page, $invite) === 1,
    'link de convite não exibido.');

// "Celular": abre o convite, ativa o push com uma inscrição apontando para o serviço de push de teste.
$phone = [];
[$status, $page] = browse($phone, $invite[1]);
webCheck($status === 200 && str_contains($page, 'data-push-panel') && str_contains($page, 'Mãe Push CI'), 'página de convite inválida.');
preg_match('/data-vapid-key="([A-Za-z0-9_-]+)"/', $page, $vapidKey);
preg_match('/data-endpoint="([^"]+)"/', $page, $endpoint);
preg_match('/data-csrf="([^"]+)"/', $page, $phoneCsrf);
webCheck(($vapidKey[1] ?? '') === WebPush::configuredPublicKey(), 'chave VAPID da página difere do servidor.');
$device = WebPush::generateKeyPair();
$auth = random_bytes(16);
$subscription = json_encode(['endpoint' => $stubUrl . '/push/celular-ci', 'expirationTime' => null,
    'keys' => ['p256dh' => WebPush::base64UrlEncode($device['public']), 'auth' => WebPush::base64UrlEncode($auth)]]);
$hostile = json_encode(['endpoint' => 'http://169.254.169.254/latest/meta-data', 'keys' => json_decode($subscription, true)['keys']]);
[$status, $json] = browse($phone, $base . $endpoint[1], ['csrf_token' => $phoneCsrf[1], 'subscription' => $hostile]);
webCheck($status === 422 && isset(json_decode($json, true)['error']), 'endpoint hostil aceito (SSRF).');
[$status, $json] = browse($phone, $base . $endpoint[1], ['csrf_token' => $phoneCsrf[1], 'subscription' => $subscription]);
webCheck($status === 201 && str_contains(json_decode($json, true)['message'] ?? '', 'Pronto'), 'ativação falhou: ' . $json);
[$status] = browse($phone, $base . $endpoint[1], ['csrf_token' => $phoneCsrf[1], 'subscription' => $subscription]);
webCheck($status === 409, 'convite reutilizado.');
[$status, $page] = browse($coord, $base . '/cadastros');
webCheck(str_contains($page, 'Push em 1 aparelho(s)'), 'cadastros não mostram o aparelho ativo.');

// Chamada com a aluna ausente → fila de push, sem e-mail.
[, $page] = browse($coord, $base . '/chamada?turma_id=' . $class[1]);
[$status, $page] = browse($coord, $base . '/chamada', ['csrf_token' => csrfFrom($page), 'class_id' => $class[1],
    'absence_date' => '2026-11-16', 'attendance[' . $student[1] . ']' => 'AUSENTE']);
webCheck($status === 200 && str_contains($page, '1 por notificação push'), 'chamada não roteou para push.');
webCheck($db->push_queue->countDocuments(['guardian_id' => $guardian['_id'], 'status' => 'AGUARDANDO']) === 1
    && $db->mail_queue->countDocuments(['recipient' => 'mae.push@example.org']) === 0, 'filas inesperadas após a chamada.');

// Worker real: entrega HTTP ao serviço de push, que o aparelho decifra.
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/bin/process_push_queue_mongodb.php'), $out, $code);
webCheck($code === 0 && str_contains(implode("\n", $out), 'processadas: 1'), 'worker de push falhou: ' . implode("\n", $out));
$files = glob($stubDir . '/celular-ci-*.json');
webCheck(count($files) === 1, 'serviço de push não recebeu a notificação.');
$record = json_decode((string)file_get_contents($files[0]), true);
$claims = PushTestSupport::verifyVapid($record['headers']['authorization'] ?? '');
webCheck($claims !== null && $claims['claims']['aud'] === $stubUrl, 'VAPID da entrega inválido.');
$notification = json_decode(PushTestSupport::decrypt(base64_decode($record['body']), $device, $auth), true);
webCheck($notification['title'] === 'Ausência de Aluna Push CI' && str_contains($notification['body'], '16/11/2026')
    && preg_match('#^/justificar/[A-Za-z0-9_-]{43}$#', $notification['url']) === 1, 'conteúdo da notificação inesperado.');

// Toque na notificação: abre a justificativa no celular e registra a resposta.
[$status, $page] = browse($phone, $base . $notification['url']);
webCheck($status === 200 && str_contains($page, 'Aluna Push CI') && str_contains($page, 'data-push-panel'), 'link do push não abriu a justificativa.');
[$status, $page] = browse($phone, $base . $notification['url'], ['csrf_token' => csrfFrom($page), 'reason' => 'CONSULTA_MEDICA', 'explanation' => '']);
webCheck($status === 200 && str_contains($page, 'Justificativa enviada'), 'justificativa pelo push falhou.');
webCheck($db->communications->countDocuments(['guardian_id' => $guardian['_id'], 'channel' => 'PUSH', 'status' => 'ENVIADO']) === 1
    && $db->justifications->countDocuments(['guardian_id' => $guardian['_id'], 'reason' => 'CONSULTA_MEDICA']) === 1
    && $db->push_queue->countDocuments(['guardian_id' => $guardian['_id'], 'status' => 'CONCLUIDO', 'body' => '']) === 1,
    'estado final no MongoDB incorreto.');

fwrite(STDOUT, "push_web: convite, ativação, chamada, entrega cifrada e justificativa pelo push passaram.\n");
