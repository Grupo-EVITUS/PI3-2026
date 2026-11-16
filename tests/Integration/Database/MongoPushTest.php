<?php
declare(strict_types=1);

use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoJustification;
use Saa\Database\MongoMailQueue;
use Saa\Database\MongoPushQueue;
use Saa\Database\MongoPushSubscriptions;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\Schema;
use Saa\Push\WebPush;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function pushAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('MongoPushTest: ' . $message);
    }
}

function pushFails(callable $work, string $exception, string $message): void
{
    try {
        $work();
    } catch (Throwable $error) {
        pushAssert($error instanceof $exception, $message . ' (' . $error::class . ': ' . $error->getMessage() . ')');
        return;
    }
    throw new RuntimeException('MongoPushTest: aceitou ' . $message);
}

/** Inscrição como o Chrome do Android enviaria (PushSubscription.toJSON()). */
function deviceSubscription(string $device): array
{
    static $keys = null;
    $keys ??= WebPush::generateKeyPair()['public'];
    return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $device, 'expirationTime' => null,
        'keys' => ['p256dh' => WebPush::base64UrlEncode($keys), 'auth' => WebPush::base64UrlEncode(random_bytes(16))]];
}

putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES');
$db = Mongo::database('saa_push_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $coord = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    (new MongoStaffRegistration($db))->register($coord, 'AOE', 'aoe@example.org', 'Senha-temporaria-2026', 'AOE');
    $aoe = $db->users->findOne(['email' => 'aoe@example.org'])['_id'];
    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($coord, 'Turma B', '6º ano', 2026, 'Tarde');
    $student = $directory->createStudent($coord, 'Aluna Push', 'PUSH-1', $class);
    $withPush = $directory->createGuardian($coord, 'Mãe com app', 'mae@example.org', 'Mãe', [$student]);
    $emailOnly = $directory->createGuardian($coord, 'Pai sem app', 'pai@example.org', 'Pai', [$student]);
    $subscriptions = new MongoPushSubscriptions($db);
    $attendance = new MongoAttendance($db);
    $absentOn = static fn(string $day): array => $attendance->record(
        $coord, $class, [(string)$student => 'AUSENTE'], $day, 'https://escola.example.org'
    );

    // Convite: somente coordenação, uso único, inscrição inválida não consome o convite.
    pushFails(fn() => $subscriptions->createInvite($aoe, $withPush), RuntimeException::class, 'convite gerado pela AOE');
    $invite = $subscriptions->createInvite($coord, $withPush);
    pushAssert($subscriptions->findInvite($invite)['guardian_name'] === 'Mãe com app', 'convite não localizado.');
    pushAssert($db->push_invites->countDocuments(['token_hash' => $invite]) === 0, 'token salvo em claro.');
    $bad = deviceSubscription('x');
    $bad['endpoint'] = 'https://atacante.example.org/coletar';
    pushFails(fn() => $subscriptions->subscribeWithInvite($invite, $bad), InvalidArgumentException::class, 'endpoint fora da allowlist');
    pushAssert($subscriptions->findInvite($invite) !== null, 'inscrição inválida consumiu o convite.');
    $subscriptions->subscribeWithInvite($invite, deviceSubscription('celular-da-mae'));
    pushFails(fn() => $subscriptions->subscribeWithInvite($invite, deviceSubscription('outro')), DomainException::class, 'convite reutilizado');
    pushFails(fn() => $subscriptions->subscribeWithInvite(str_repeat('a', 43), deviceSubscription('outro')), DomainException::class, 'convite inexistente');
    pushAssert($subscriptions->activeCount($withPush) === 1 && $subscriptions->activeCount($emailOnly) === 0,
        'contagem de aparelhos incorreta.');

    // Chamada: quem tem aparelho vai para push (e-mail guardado como reserva); quem não tem, e-mail.
    $result = $absentOn('2026-11-16');
    pushAssert($result['notifications'] === 2 && $result['push_notifications'] === 1, 'resumo da chamada sem push.');
    pushAssert($db->push_queue->countDocuments(['guardian_id' => $withPush, 'status' => 'AGUARDANDO']) === 1
        && $db->mail_queue->countDocuments(['recipient' => 'pai@example.org']) === 1
        && $db->mail_queue->countDocuments(['recipient' => 'mae@example.org']) === 0, 'roteamento push/e-mail incorreto.');
    pushAssert($db->communications->countDocuments(['guardian_id' => $withPush, 'channel' => 'PUSH']) === 1
        && $db->communications->countDocuments(['guardian_id' => $emailOnly, 'channel' => 'EMAIL']) === 1,
        'canal da comunicação não registrado.');

    // Falha transitória reagenda; retry antes do prazo não é processado.
    $worker = new MongoPushQueue($db);
    pushAssert($worker->process(static fn(): int => throw new RuntimeException('rede')) === 1, 'falha não processada.');
    $job = $db->push_queue->findOne();
    pushAssert($job['status'] === 'AGUARDANDO' && (int)$job['attempts'] === 1 && $job['next_attempt_at'] instanceof UTCDateTime
        && $db->communications->countDocuments(['channel' => 'PUSH', 'status' => 'PENDENTE']) === 1, 'retry não agendado.');
    pushAssert($worker->process(static fn(): int => throw new LogicException('adiantado')) === 0, 'retry adiantado.');

    // Entrega: payload com link de justificativa que funciona; fila limpa após o envio.
    $db->push_queue->updateOne(['_id' => $job['_id']], ['$set' => ['next_attempt_at' => new UTCDateTime(0)]]);
    $sent = [];
    $worker->process(static function (array $subscription, string $payload, string $topic) use (&$sent): int {
        $sent[] = [$subscription, json_decode($payload, true), $topic];
        return 201;
    });
    pushAssert(count($sent) === 1 && str_ends_with($sent[0][0]['endpoint'], '/celular-da-mae')
        && $sent[0][1]['title'] === 'Ausência de Aluna Push' && str_starts_with($sent[0][1]['url'], '/justificar/')
        && str_starts_with($sent[0][1]['tag'], 'saa-') && preg_match('/^[a-f0-9]{24}$/', $sent[0][2]) === 1,
        'payload de push inesperado.');
    $token = substr($sent[0][1]['url'], strlen('/justificar/'));
    pushAssert((new MongoJustification($db))->find($token)['student_name'] === 'Aluna Push', 'link do push não abre a justificativa.');
    $job = $db->push_queue->findOne();
    pushAssert($job['status'] === 'CONCLUIDO' && $job['body'] === '' && $job['url'] === '' && $job['fallback_email'] === null
        && $db->communications->countDocuments(['channel' => 'PUSH', 'status' => 'ENVIADO']) === 1
        && $db->push_subscriptions->countDocuments(['last_success_at' => ['$type' => 'date']]) === 1, 'entrega não registrada.');
    pushAssert($worker->process(static fn(): int => throw new LogicException('reenvio')) === 0, 'reenvio após conclusão.');

    // Aparelho extinto (410): desativado e aviso encaminhado por e-mail na mesma transação.
    $absentOn('2026-11-17');
    $worker->process(static fn(): int => 410);
    $job = $db->push_queue->findOne(['status' => 'ENCAMINHADO_EMAIL']);
    pushAssert($job !== null && $job['fallback_email'] === null && $subscriptions->activeCount($withPush) === 0
        && $db->push_subscriptions->countDocuments(['revoke_reason' => 'EXPIRADA_NO_SERVICO']) === 1, 'aparelho extinto mantido.');
    $fallback = $db->mail_queue->findOne(['communication_id' => $job['communication_id']]);
    pushAssert($fallback !== null && $fallback['recipient'] === 'mae@example.org' && $fallback['status'] === 'AGUARDANDO'
        && str_contains($fallback['body'], 'https://escola.example.org/justificar/'), 'e-mail de reserva não enfileirado.');
    pushAssert($db->communications->countDocuments(['_id' => $job['communication_id'], 'channel' => 'EMAIL', 'status' => 'PENDENTE']) === 1,
        'comunicação não migrou para e-mail.');
    $mailed = [];
    (new MongoMailQueue($db))->process(static function (array $mail) use (&$mailed): void { $mailed[] = $mail['recipient']; });
    pushAssert(in_array('mae@example.org', $mailed, true)
        && $db->communications->countDocuments(['_id' => $job['communication_id'], 'status' => 'ENVIADO']) === 1,
        'e-mail de reserva não entregue.');

    // Sem aparelho ativo, a chamada seguinte já vai direto para e-mail.
    $result = $absentOn('2026-11-18');
    pushAssert($result['push_notifications'] === 0 && $db->push_queue->countDocuments() === 2, 'push para aparelho desativado.');

    // Falhas transitórias esgotadas também encaminham para e-mail.
    $subscriptions->subscribeWithInvite($subscriptions->createInvite($coord, $withPush), deviceSubscription('celular-novo'));
    $absentOn('2026-11-19');
    for ($attempt = 1; $attempt <= MongoPushQueue::MAX_ATTEMPTS; $attempt++) {
        $db->push_queue->updateMany(['status' => 'AGUARDANDO'], ['$set' => ['next_attempt_at' => null]]);
        $worker->process(static fn(): int => 503);
    }
    pushAssert($db->push_queue->countDocuments(['status' => 'ENCAMINHADO_EMAIL']) === 2
        && $subscriptions->activeCount($withPush) === 1, '503 repetido não encaminhou para e-mail ou desativou aparelho.');

    // Link do comunicado por e-mail também ativa o push, no máximo 5 aparelhos ativos por responsável.
    $mailJob = $db->mail_queue->findOne(['recipient' => 'pai@example.org', 'status' => 'CONCLUIDO']);
    pushAssert($mailJob !== null, 'e-mail do pai não processado.');
    $payToken = null;
    $absentOn('2026-11-20');
    (new MongoMailQueue($db))->process(static function (array $mail) use (&$payToken): void {
        if ($mail['recipient'] === 'pai@example.org' && preg_match('#/justificar/([A-Za-z0-9_-]{43})#', $mail['body'], $m)) {
            $payToken = $m[1];
        }
    });
    pushAssert(is_string($payToken), 'link do pai não capturado.');
    for ($device = 1; $device <= 6; $device++) {
        $subscriptions->subscribeWithCommunication($payToken, deviceSubscription('pai-' . $device));
    }
    pushAssert($subscriptions->activeCount($emailOnly) === MongoPushSubscriptions::MAX_ACTIVE_PER_GUARDIAN
        && $db->push_subscriptions->countDocuments(['guardian_id' => $emailOnly, 'revoke_reason' => 'LIMITE_APARELHOS']) === 1,
        'limite de aparelhos não aplicado.');
    $subscriptions->subscribeWithCommunication($payToken, deviceSubscription('pai-6'));
    pushAssert($db->push_subscriptions->countDocuments(['guardian_id' => $emailOnly]) === 6, 'reinscrição duplicou aparelho.');
    pushFails(fn() => $subscriptions->subscribeWithCommunication(str_repeat('b', 43), deviceSubscription('z')),
        DomainException::class, 'link de comunicado inexistente');
    pushAssert($subscriptions->activeCounts()[(string)$emailOnly] === 5, 'contagem agregada incorreta.');

    // Responsável arquivado: convite pendente deixa de valer.
    $pending = $subscriptions->createInvite($coord, $emailOnly);
    $directory->archive($coord, 'responsavel', $emailOnly);
    pushAssert($subscriptions->findInvite($pending) === null, 'convite de responsável arquivado continua válido.');
    pushAssert($db->operation_logs->countDocuments(['action' => 'CONVITE_PUSH_GERADO']) === 3
        && $db->operation_logs->countDocuments(['action' => 'PUSH_ENVIADO']) === 1
        && $db->operation_logs->countDocuments(['action' => 'PUSH_ENCAMINHADO_EMAIL']) === 2, 'auditoria do push incompleta.');

    fwrite(STDOUT, "MongoPushTest: convite, roteamento, entrega, retry, aparelho extinto e e-mail de reserva passaram.\n");
} finally {
    $db->drop();
}
