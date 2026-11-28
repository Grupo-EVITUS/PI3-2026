<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAbsenceCancellation;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoFrequencyReport;
use Saa\Database\MongoJustification;
use Saa\Database\MongoMailQueue;
use Saa\Database\MongoOccurrences;
use Saa\Database\MongoPushQueue;
use Saa\Database\MongoPushSubscriptions;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\Schema;
use Saa\Push\WebPush;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function undoAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('MongoAbsenceCancellationTest: ' . $message);
    }
}

function undoFails(callable $work, string $exception, string $message): Throwable
{
    try {
        $work();
    } catch (Throwable $error) {
        undoAssert($error instanceof $exception, $message . ' (' . $error::class . ': ' . $error->getMessage() . ')');
        return $error;
    }
    throw new RuntimeException('MongoAbsenceCancellationTest: aceitou ' . $message);
}

/** Inscrição como o Chrome do Android enviaria (PushSubscription.toJSON()). */
function undoDevice(string $device): array
{
    return ['endpoint' => 'https://fcm.googleapis.com/fcm/send/' . $device, 'expirationTime' => null,
        'keys' => ['p256dh' => WebPush::base64UrlEncode(WebPush::generateKeyPair()['public']),
            'auth' => WebPush::base64UrlEncode(random_bytes(16))]];
}

putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES');
$db = Mongo::database('saa_undo_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $coord = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $staff = new MongoStaffRegistration($db);
    $aoe = $staff->register($coord, 'Agente AOE', 'aoe@example.org', 'Senha-temporaria-2026', 'AOE');
    $director = $staff->register($coord, 'Diretora', 'diretora@example.org', 'Senha-temporaria-2026', 'DIRETOR');
    $formerAoe = $staff->register($coord, 'AOE desligado', 'ex-aoe@example.org', 'Senha-temporaria-2026', 'AOE');
    $db->users->updateOne(['_id' => $formerAoe], ['$set' => ['active' => false]]);

    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($coord, 'Turma Correção', '5º ano', 2026, 'Manhã');
    $students = [
        'ana' => $ana = $directory->createStudent($coord, 'Ana Email', 'UNDO-1', $class),
        'bia' => $bia = $directory->createStudent($coord, 'Bia Push', 'UNDO-2', $class),
        'caio' => $caio = $directory->createStudent($coord, 'Caio Presente', 'UNDO-3', $class),
        'duda' => $duda = $directory->createStudent($coord, 'Duda Sem Responsável', null, $class),
    ];
    $anaMother = $directory->createGuardian($coord, 'Mãe da Ana', 'ana.mae@example.org', 'Mãe', [$ana]);
    $biaMother = $directory->createGuardian($coord, 'Mãe da Bia', 'bia.mae@example.org', 'Mãe', [$bia]);
    $directory->createGuardian($coord, 'Pai da Bia', 'bia.pai@example.org', 'Pai', [$bia]);
    $directory->createGuardian($coord, 'Pai do Caio', 'caio.pai@example.org', 'Pai', [$caio]);
    $subscriptions = new MongoPushSubscriptions($db);
    $subscriptions->subscribeWithInvite($subscriptions->createInvite($coord, $biaMother), undoDevice('celular-mae-bia'));

    $attendance = new MongoAttendance($db);
    $service = new MongoAbsenceCancellation($db);
    $occurrences = new MongoOccurrences($db);
    $justification = new MongoJustification($db);
    $mailWorker = new MongoMailQueue($db);
    $pushWorker = new MongoPushQueue($db);
    $call = static function (string $day, array $absent) use ($attendance, $coord, $class, $students): array {
        $statuses = [];
        foreach ($students as $key => $id) {
            $statuses[(string)$id] = in_array($key, $absent, true) ? 'AUSENTE' : 'PRESENTE';
        }
        return $attendance->record($coord, $class, $statuses, $day, 'https://escola.example.org');
    };
    $absenceOf = static fn(ObjectId $student, string $day): array
        => $db->absences->findOne(['student_id' => $student, 'absence_date' => $day]);
    $noticeOf = static fn(ObjectId $absenceId, string $channel): array => $db->communications->findOne(
        ['absence_id' => $absenceId, 'channel' => $channel, 'kind' => ['$ne' => 'CORRECAO']]
    );
    $sent = [];
    $capture = static function (array $mail) use (&$sent): void {
        $sent[] = $mail;
    };
    $drain = static function () use ($mailWorker, $capture): void {
        while ($mailWorker->process($capture) > 0) {
        }
    };
    $tokenFor = static function (string $recipient) use (&$sent): ?string {
        foreach ($sent as $mail) {
            if ($mail['recipient'] === $recipient
                && preg_match('#/justificar/([A-Za-z0-9_-]{43})#', $mail['body'], $match) === 1) {
                return $match[1];
            }
        }
        return null;
    };
    // Se um job for reivindicado, o worker conta a falha como processada: process() deixa de devolver 0.
    $neverSend = static fn() => throw new LogicException('Aviso de falta desfeita foi reivindicado.');

    // 1. E-mail ainda na fila: cancelado antes do envio, sem correção; chamada e frequência corrigidas.
    $first = $call('2026-11-02', ['ana']);
    $sessionId = new ObjectId($first['attendance_session_id']);
    $before = $db->attendance_sessions->findOne(['_id' => $sessionId]);
    $absence = $absenceOf($ana, '2026-11-02');
    undoAssert($service->preview($absence['_id']) === ['queued' => 1, 'corrections' => 0], 'prévia do e-mail na fila.');
    $result = $service->cancel($aoe, $absence['_id']);
    undoAssert($result === ['absence_id' => (string)$absence['_id'], 'attendance_session_id' => (string)$sessionId,
        'cancelled_notices' => 1, 'corrections' => 0], 'resumo do e-mail na fila: ' . json_encode($result));
    $absence = $db->absences->findOne(['_id' => $absence['_id']]);
    undoAssert($absence['status'] === 'CANCELADA' && $absence['cancel_reason'] === MongoAbsenceCancellation::DEFAULT_REASON
        && $absence['cancelled_by'] == $aoe && $absence['cancelled_at'] instanceof UTCDateTime, 'ausência não ficou desfeita.');
    $notice = $noticeOf($absence['_id'], 'EMAIL');
    $job = $db->mail_queue->findOne(['communication_id' => $notice['_id']]);
    undoAssert($notice['status'] === 'CANCELADA' && $notice['cancelled_at'] instanceof UTCDateTime
        && $job['status'] === 'CANCELADO' && $job['body'] === '', 'e-mail na fila não foi cancelado.');
    undoAssert($db->communications->countDocuments(['kind' => 'CORRECAO']) === 0, 'correção para aviso que não saiu.');
    undoAssert($mailWorker->process($neverSend) === 0, 'o worker reivindicou um job CANCELADO.');
    $after = $db->attendance_sessions->findOne(['_id' => $sessionId]);
    $entry = array_values(array_filter($after['entries'], static fn(array $row): bool => $row['student_id'] == $ana))[0];
    $expectedHash = hash('sha256', json_encode(array_map(
        static fn(array $row): array => [(string)$row['student_id'], $row['status']], $after['entries']
    ), JSON_THROW_ON_ERROR));
    undoAssert($entry['status'] === 'PRESENTE' && $after['roster_hash'] === $expectedHash
        && $after['roster_hash'] !== $before['roster_hash'] && count($after['entries']) === 4
        && count($after['corrections'] ?? []) === 1, 'entrada da chamada ou roster_hash não corrigidos.');
    $fix = $after['corrections'][0];
    undoAssert($fix['student_id'] == $ana && $fix['absence_id'] == $absence['_id'] && $fix['from'] === 'AUSENTE'
        && $fix['to'] === 'PRESENTE' && $fix['by'] == $aoe && $fix['at'] instanceof UTCDateTime
        && $fix['reason'] === MongoAbsenceCancellation::DEFAULT_REASON, 'registro da correção na chamada incompleto.');
    $report = (new MongoFrequencyReport($db))->report('2026-11-02', '2026-11-02')['summary'];
    undoAssert($report['present'] === 4 && $report['absent'] === 0, 'frequência ainda conta a falta desfeita.');
    $summary = $occurrences->attendanceSummary($sessionId);
    undoAssert($summary['counts'] === ['present' => 4, 'absent' => 0, 'total' => 4, 'undone' => 1]
        && count($summary['absences']) === 1 && $summary['absences'][0]['status'] === 'CANCELADA'
        && $summary['absences'][0]['cancellation']['cancelled_by_name'] === 'Agente AOE'
        && $summary['absences'][0]['communications'][0]['status'] === 'CANCELADA'
        && $summary['completed_by_name'] === 'Coordenação' && $summary['without_guardians'] === [],
        'resumo da chamada após desfazer.');
    $error = undoFails(fn() => $service->cancel($coord, $absence['_id']), DomainException::class, 'desfazer duas vezes');
    undoAssert($error->getMessage() === 'Esta falta já foi desfeita.', 'mensagem de falta já desfeita.');
    $log = $db->operation_logs->findOne(['action' => 'AUSENCIA_DESFEITA', 'entity_id' => (string)$absence['_id']]);
    undoAssert($log !== null && $log['entity'] === 'ausencia' && $log['user_id'] == $aoe
        && str_contains($log['details'], MongoAbsenceCancellation::DEFAULT_REASON) && !str_contains($log['details'], '@')
        && $db->operation_logs->countDocuments(['action' => 'AUSENCIA_DESFEITA']) === 1, 'auditoria da falta desfeita.');

    // 2. E-mail já enviado: correção por e-mail; o link abre como cancelado e recusa justificativa.
    $call('2026-11-03', ['ana']);
    $sent = [];
    $drain();
    $token = $tokenFor('ana.mae@example.org');
    undoAssert($token !== null, 'link do aviso não capturado.');
    $absence = $absenceOf($ana, '2026-11-03');
    $original = $noticeOf($absence['_id'], 'EMAIL');
    $result = $service->cancel($coord, $absence['_id'], "  Marquei a aluna errada.\r\nEla estava na sala.  ");
    undoAssert($result['cancelled_notices'] === 0 && $result['corrections'] === 1, 'resumo do e-mail enviado.');
    undoAssert($db->absences->findOne(['_id' => $absence['_id']])['cancel_reason'] === "Marquei a aluna errada.\nEla estava na sala.",
        'motivo não normalizado.');
    $correction = $db->communications->findOne(['absence_id' => $absence['_id'], 'kind' => 'CORRECAO']);
    $correctionJob = $correction === null ? null : $db->mail_queue->findOne(['communication_id' => $correction['_id']]);
    undoAssert($correction !== null && $correction['correction_of_id'] == $original['_id'] && $correction['channel'] === 'EMAIL'
        && $correction['status'] === 'PENDENTE' && $correction['email_to'] === 'ana.mae@example.org'
        && $correction['guardian_id'] == $anaMother && preg_match('/^[a-f0-9]{64}$/', $correction['token_hash']) === 1
        && $correction['token_hash'] !== $original['token_hash'], 'comunicação de correção incorreta.');
    undoAssert($correctionJob !== null && $correctionJob['status'] === 'AGUARDANDO'
        && $correctionJob['recipient'] === 'ana.mae@example.org'
        && $correctionJob['subject'] === 'Correção: desconsidere o aviso de ausência — Ana Email'
        && str_contains($correctionJob['body'], 'Olá, Mãe da Ana.')
        && str_contains($correctionJob['body'], 'Ana Email não compareceu à turma Turma Correção em 03/11/2026 foi um erro de registro')
        && str_contains($correctionJob['body'], 'Ana Email estava presente')
        && str_contains($correctionJob['body'], 'O link de justificativa não é mais necessário')
        && str_ends_with($correctionJob['body'], "Equipe escolar") && !str_contains($correctionJob['body'], '/justificar/'),
        'texto do e-mail de correção.');
    undoAssert($db->communications->findOne(['_id' => $original['_id']])['status'] === 'ENVIADO', 'aviso enviado foi alterado.');
    $sent = [];
    undoAssert($mailWorker->process($capture) === 1 && count($sent) === 1
        && $sent[0]['subject'] === $correctionJob['subject'] && $sent[0]['recipient'] === 'ana.mae@example.org',
        'o worker de e-mail não enviou a correção.');
    $correctionJob = $db->mail_queue->findOne(['_id' => $correctionJob['_id']]);
    undoAssert($db->communications->findOne(['_id' => $correction['_id']])['status'] === 'ENVIADO'
        && $correctionJob['status'] === 'CONCLUIDO' && $correctionJob['body'] === '', 'desfecho da correção.');
    $view = $justification->find($token);
    undoAssert($view !== null && $view['absence_status'] === 'CANCELADA' && $view['student_name'] === 'Ana Email'
        && $view['cancelled_at'] !== null, 'o link da falta desfeita não mostra o cancelamento.');
    $error = undoFails(fn() => $justification->submit($token, 'DOENCA', ''), DomainException::class, 'justificativa de falta desfeita');
    undoAssert($error->getMessage() === MongoJustification::CANCELLED_MESSAGE
        && $db->justifications->countDocuments(['absence_id' => $absence['_id']]) === 0
        && $db->absences->findOne(['_id' => $absence['_id']])['status'] === 'CANCELADA', 'justificativa aceita após desfazer.');
    $row = array_values(array_filter($occurrences->list('CANCELADA'), static fn(array $r): bool => $r['id'] === (string)$absence['_id']))[0];
    undoAssert($row['status'] === 'CANCELADA' && $row['communication_count'] === 1 && $row['correction_count'] === 1,
        'listagem da falta desfeita: ' . json_encode($row));

    // 3. Push e e-mail ainda na fila: os dois são cancelados; nada sai, nem o e-mail de reserva.
    $call('2026-11-04', ['bia']);
    $absence = $absenceOf($bia, '2026-11-04');
    $pushNotice = $noticeOf($absence['_id'], 'PUSH');
    $pushJob = $db->push_queue->findOne(['communication_id' => $pushNotice['_id']]);
    undoAssert($pushJob['status'] === 'AGUARDANDO' && $pushJob['fallback_email'] !== null, 'push não enfileirado.');
    undoAssert($service->preview($absence['_id']) === ['queued' => 2, 'corrections' => 0], 'prévia de push e e-mail na fila.');
    $result = $service->cancel($aoe, $absence['_id']);
    undoAssert($result['cancelled_notices'] === 2 && $result['corrections'] === 0, 'resumo do push na fila.');
    $pushJob = $db->push_queue->findOne(['_id' => $pushJob['_id']]);
    undoAssert($pushJob['status'] === 'CANCELADO' && $pushJob['body'] === '' && $pushJob['url'] === ''
        && $pushJob['fallback_email'] === null && $pushJob['base_url'] === null && $pushJob['reminder_due_at'] === null,
        'push na fila não foi cancelado ou manteve conteúdo com link.');
    undoAssert($db->communications->countDocuments(['absence_id' => $absence['_id'], 'status' => 'CANCELADA']) === 2,
        'comunicações do push e do e-mail não foram canceladas.');
    undoAssert($pushWorker->process(static fn(): int => throw new LogicException('push cancelado enviado')) === 0
        && $mailWorker->process($neverSend) === 0
        && $db->mail_queue->countDocuments(['recipient' => 'bia.mae@example.org']) === 0, 'aviso cancelado saiu da fila.');

    // 4. Push entregue com lembrete agendado e e-mail entregue: duas correções e lembrete dispensado.
    $call('2026-11-05', ['bia']);
    undoAssert($pushWorker->process(static fn(): int => 201) === 1, 'push não entregue.');
    $sent = [];
    $drain();
    $absence = $absenceOf($bia, '2026-11-05');
    $pushJob = $db->push_queue->findOne(['communication_id' => $noticeOf($absence['_id'], 'PUSH')['_id']]);
    undoAssert($pushJob['status'] === 'CONCLUIDO' && $pushJob['reminder_due_at'] instanceof UTCDateTime
        && $pushJob['base_url'] === 'https://escola.example.org', 'lembrete do push não agendado.');
    undoAssert($service->preview($absence['_id']) === ['queued' => 0, 'corrections' => 2], 'prévia com avisos entregues.');
    $result = $service->cancel($aoe, $absence['_id']);
    undoAssert($result['cancelled_notices'] === 0 && $result['corrections'] === 2, 'resumo dos avisos entregues.');
    $pushJob = $db->push_queue->findOne(['_id' => $pushJob['_id']]);
    undoAssert($pushJob['reminder_due_at'] === null && $pushJob['reminder_status'] === 'DISPENSADO' && $pushJob['base_url'] === null,
        'lembrete do push entregue não foi dispensado.');
    $recipients = array_map(static fn(array $c): string => $c['email_to'],
        $db->communications->find(['absence_id' => $absence['_id'], 'kind' => 'CORRECAO'])->toArray());
    sort($recipients);
    undoAssert($recipients === ['bia.mae@example.org', 'bia.pai@example.org'], 'correções: ' . json_encode($recipients));
    undoAssert($pushWorker->remindUnanswered() === 0, 'lembrete processado para falta desfeita.');
    // Prazo vencido gravado antes (dado antigo): o worker dispensa o lembrete sem criar e-mail.
    $db->push_queue->updateOne(['_id' => $pushJob['_id']], ['$set' => [
        'reminder_due_at' => new UTCDateTime(0), 'base_url' => 'https://escola.example.org', 'reminder_status' => null,
    ]]);
    $mails = $db->mail_queue->countDocuments();
    undoAssert($pushWorker->remindUnanswered() === 1 && $db->mail_queue->countDocuments() === $mails
        && $db->push_queue->findOne(['_id' => $pushJob['_id']])['reminder_status'] === 'DISPENSADO'
        && $db->operation_logs->countDocuments(['action' => 'PUSH_LEMBRETE_DISPENSADO',
            'details' => 'Ausência desfeita pela escola.']) === 1, 'lembrete vencido de falta desfeita não dispensado.');
    $sent = [];
    $drain();
    undoAssert(count($sent) === 2 && array_filter($sent, static fn(array $m): bool => !str_starts_with($m['subject'], 'Correção:')) === [],
        'correções do push e do e-mail não enviadas.');

    // 5. Push e lembrete por e-mail entregues ao mesmo responsável: uma única correção para ele.
    $call('2026-11-06', ['bia']);
    $pushWorker->process(static fn(): int => 201);
    $absence = $absenceOf($bia, '2026-11-06');
    $db->push_queue->updateOne(['communication_id' => $noticeOf($absence['_id'], 'PUSH')['_id']],
        ['$set' => ['reminder_due_at' => new UTCDateTime(0)]]);
    undoAssert($pushWorker->remindUnanswered() === 1, 'lembrete do push não criado.');
    $sent = [];
    $drain();
    undoAssert(count($sent) === 2, 'aviso do pai e lembrete da mãe não enviados.');
    $result = $service->cancel($coord, $absence['_id']);
    undoAssert($result['corrections'] === 2 && $result['cancelled_notices'] === 0
        && $db->communications->countDocuments(['absence_id' => $absence['_id'], 'kind' => 'CORRECAO', 'guardian_id' => $biaMother]) === 1,
        'correção repetida para o mesmo responsável.');
    $drain();

    // 6. Worker enviando o aviso (PROCESSANDO) quando a falta é desfeita: correção, e o worker conclui sem erro.
    $call('2026-11-09', ['ana']);
    $absence = $absenceOf($ana, '2026-11-09');
    $inFlight = null;
    $sent = [];
    $processed = $mailWorker->process(static function (array $mail) use (&$inFlight, &$sent, $service, $aoe, $absence, $db): void {
        if ($inFlight === null) {
            undoAssert($db->mail_queue->countDocuments(['recipient' => 'ana.mae@example.org', 'status' => 'PROCESSANDO']) === 1,
                'o aviso não estava em envio.');
            $inFlight = $service->cancel($aoe, $absence['_id']);
        }
        $sent[] = $mail;
    });
    $notice = $noticeOf($absence['_id'], 'EMAIL');
    undoAssert($processed === 2 && $inFlight['cancelled_notices'] === 0 && $inFlight['corrections'] === 1
        && $notice['status'] === 'ENVIADO' && $db->mail_queue->findOne(['communication_id' => $notice['_id']])['status'] === 'CONCLUIDO',
        'job em envio: o worker não concluiu ou a correção faltou.');
    undoAssert(count($sent) === 2 && str_contains($sent[0]['body'], '/justificar/') && str_starts_with($sent[1]['subject'], 'Correção:'),
        'ordem do aviso e da correção.');

    // 7. Falha de SMTP no envio em curso, com a falta já desfeita: o aviso é cancelado, sem nova tentativa.
    $call('2026-11-10', ['ana']);
    $absence = $absenceOf($ana, '2026-11-10');
    $inFlight = null;
    $sent = [];
    $processed = $mailWorker->process(static function (array $mail) use (&$inFlight, &$sent, $service, $aoe, $absence): void {
        if ($inFlight === null) {
            $inFlight = $service->cancel($aoe, $absence['_id']);
            throw new RuntimeException('SMTP fora do ar');
        }
        $sent[] = $mail;
    });
    $notice = $noticeOf($absence['_id'], 'EMAIL');
    $job = $db->mail_queue->findOne(['communication_id' => $notice['_id']]);
    undoAssert($processed === 2 && $inFlight['corrections'] === 1 && $notice['status'] === 'CANCELADA'
        && $job['status'] === 'CANCELADO' && $job['body'] === '' && $job['next_attempt_at'] === null
        && count($sent) === 1 && str_starts_with($sent[0]['subject'], 'Correção:')
        && $db->operation_logs->countDocuments(['action' => 'EMAIL_CANCELADO', 'entity_id' => (string)$notice['_id']]) === 1,
        'falha depois de desfazer não cancelou o aviso.');
    undoAssert($mailWorker->process($neverSend) === 0, 'o aviso da falta desfeita voltou para a fila.');

    // 8. Worker caiu no meio do envio (reserva expirada): o aviso é reivindicado de novo, mas não sai.
    $call('2026-11-11', ['ana']);
    $absence = $absenceOf($ana, '2026-11-11');
    $notice = $noticeOf($absence['_id'], 'EMAIL');
    $db->mail_queue->updateOne(['communication_id' => $notice['_id']], [
        '$set' => ['status' => 'PROCESSANDO', 'started_at' => new UTCDateTime((time() - 600) * 1000), 'claim_id' => new ObjectId()],
        '$inc' => ['attempts' => 1],
    ]);
    $result = $service->cancel($aoe, $absence['_id']);
    undoAssert($result['corrections'] === 1 && $result['cancelled_notices'] === 0
        && $db->mail_queue->findOne(['communication_id' => $notice['_id']])['status'] === 'PROCESSANDO',
        'job reivindicado pelo worker foi alterado.');
    $sent = [];
    undoAssert($mailWorker->process($capture) === 2 && count($sent) === 1 && str_starts_with($sent[0]['subject'], 'Correção:')
        && $db->communications->findOne(['_id' => $notice['_id']])['status'] === 'CANCELADA'
        && $db->mail_queue->findOne(['communication_id' => $notice['_id']])['status'] === 'CANCELADO',
        'aviso da falta desfeita enviado após reserva expirada.');

    // 9. Push em envio quando a falta é desfeita e entregue: correção para a mãe, sem lembrete.
    $call('2026-11-12', ['bia']);
    $absence = $absenceOf($bia, '2026-11-12');
    $inFlight = null;
    $pushWorker->process(static function () use (&$inFlight, $service, $aoe, $absence): int {
        $inFlight ??= $service->cancel($aoe, $absence['_id']);
        return 201;
    });
    $pushNotice = $noticeOf($absence['_id'], 'PUSH');
    $pushJob = $db->push_queue->findOne(['communication_id' => $pushNotice['_id']]);
    undoAssert($inFlight['cancelled_notices'] === 1 && $inFlight['corrections'] === 1 && $pushNotice['status'] === 'ENVIADO'
        && $pushJob['status'] === 'CONCLUIDO' && $pushJob['reminder_due_at'] === null && $pushJob['base_url'] === null
        && $db->communications->countDocuments(['absence_id' => $absence['_id'], 'kind' => 'CORRECAO', 'guardian_id' => $biaMother]) === 1,
        'push entregue depois de desfazer: lembrete agendado ou correção ausente.');
    $drain();

    // 10. Push em envio não entregue depois de desfeita: sem e-mail de reserva e sem nova tentativa.
    foreach (['2026-11-13' => 400, '2026-11-16' => 503] as $day => $status) {
        $call($day, ['bia']);
        $absence = $absenceOf($bia, $day);
        $inFlight = null;
        $pushWorker->process(static function () use (&$inFlight, $service, $coord, $absence, $status): int {
            $inFlight ??= $service->cancel($coord, $absence['_id']);
            return $status;
        });
        $pushNotice = $noticeOf($absence['_id'], 'PUSH');
        $pushJob = $db->push_queue->findOne(['communication_id' => $pushNotice['_id']]);
        undoAssert($inFlight['corrections'] === 1 && $pushNotice['status'] === 'CANCELADA'
            && $pushJob['status'] === 'CANCELADO' && $pushJob['fallback_email'] === null && $pushJob['body'] === ''
            && $pushJob['url'] === '' && $pushJob['next_attempt_at'] === null && $pushJob['reminder_due_at'] === null
            && $db->mail_queue->countDocuments(['communication_id' => $pushNotice['_id']]) === 0
            && $db->operation_logs->countDocuments(['action' => 'PUSH_CANCELADO', 'entity_id' => (string)$pushNotice['_id']]) === 1,
            'push ' . $status . ' depois de desfazer gerou e-mail de reserva ou nova tentativa.');
        $drain();
    }

    // 11. Ausência já respondida também pode ser desfeita; a justificativa fica no histórico.
    $call('2026-11-17', ['ana']);
    $sent = [];
    $drain();
    $token = $tokenFor('ana.mae@example.org');
    $justification->submit((string)$token, 'DOENCA', '');
    $absence = $absenceOf($ana, '2026-11-17');
    undoAssert($absence['status'] === 'RESPONDIDA', 'justificativa não registrada.');
    $result = $service->cancel($coord, $absence['_id'], 'Chamada lançada na turma errada.');
    undoAssert($result['corrections'] === 1 && $db->absences->findOne(['_id' => $absence['_id']])['status'] === 'CANCELADA'
        && $db->justifications->countDocuments(['absence_id' => $absence['_id']]) === 1
        && $justification->find((string)$token)['absence_status'] === 'CANCELADA', 'falta respondida não foi desfeita.');
    $detail = $occurrences->detail($absence['_id']);
    $corrections = array_values(array_filter($detail['communications'], static fn(array $c): bool => $c['is_correction']));
    undoAssert($detail['status'] === 'CANCELADA' && $detail['cancellation']['cancelled_by_name'] === 'Coordenação'
        && $detail['cancellation']['reason'] === 'Chamada lançada na turma errada.' && $detail['cancellation']['cancelled_at'] !== null
        && $detail['justification']['reason'] === 'DOENCA' && $detail['attendance_session_id'] === (string)$absence['attendance_session_id']
        && count($corrections) === 1 && $corrections[0]['status'] === 'PENDENTE', 'detalhe da falta desfeita.');
    $drain();

    // 12. Só AOE e Coordenação ativas desfazem; motivo validado; aluno sem responsável.
    $call('2026-11-18', ['caio', 'duda']);
    $caioAbsence = $absenceOf($caio, '2026-11-18');
    foreach (['a Diretora' => $director, 'AOE inativa' => $formerAoe, 'usuário inexistente' => new ObjectId()] as $label => $who) {
        undoFails(fn() => $service->cancel($who, $caioAbsence['_id']), RuntimeException::class, 'desfazer por ' . $label);
    }
    undoAssert($db->absences->findOne(['_id' => $caioAbsence['_id']])['status'] === 'PENDENTE'
        && $db->mail_queue->countDocuments(['recipient' => 'caio.pai@example.org', 'status' => 'AGUARDANDO']) === 1,
        'pedido recusado alterou dados.');
    undoFails(fn() => $service->cancel($aoe, new ObjectId()), InvalidArgumentException::class, 'falta inexistente');
    foreach ([str_repeat('a', 301), "Motivo\x07", "Motivo\tcom tabulação", "\xC3\x28"] as $bad) {
        undoFails(fn() => $service->cancel($aoe, $caioAbsence['_id'], $bad), InvalidArgumentException::class,
            'motivo inválido ' . json_encode(mb_convert_encoding($bad, 'UTF-8', 'UTF-8')));
    }
    // O limite é de caracteres, não de bytes, também no validador do MongoDB.
    $result = $service->cancel($aoe, $caioAbsence['_id'], str_repeat('é', 300));
    undoAssert($result['cancelled_notices'] === 1
        && mb_strlen($db->absences->findOne(['_id' => $caioAbsence['_id']])['cancel_reason']) === 300, 'motivo de 300 caracteres.');
    $sessionId = $caioAbsence['attendance_session_id'];
    undoAssert($occurrences->attendanceSummary($sessionId)['without_guardians'] === ['Duda Sem Responsável'],
        'resumo não aponta aluno sem responsável.');
    $result = $service->cancel($aoe, $absenceOf($duda, '2026-11-18')['_id']);
    $summary = $occurrences->attendanceSummary($sessionId);
    undoAssert($result['cancelled_notices'] === 0 && $result['corrections'] === 0 && $summary['without_guardians'] === []
        && $summary['counts'] === ['present' => 4, 'absent' => 0, 'total' => 4, 'undone' => 2], 'falta sem responsável.');

    // 13. Falta antiga, sem chamada: desfeita sem tocar em chamada alguma.
    $legacy = new ObjectId();
    $db->absences->insertOne(['_id' => $legacy, 'student_id' => $duda, 'class_id' => $class, 'absence_date' => '2026-10-20',
        'status' => 'PENDENTE', 'registered_by' => $coord, 'created_at' => new UTCDateTime(), 'class_snapshot_source' => 'unknown']);
    undoAssert($service->cancel($aoe, $legacy) === ['absence_id' => (string)$legacy, 'attendance_session_id' => null,
        'cancelled_notices' => 0, 'corrections' => 0], 'falta antiga sem chamada.');

    // 14. Ocorrências: filtro Desfeita, falha deixa de ser pendência e reenvio recusado.
    $failed = $db->communications->findOne(['absence_id' => $caioAbsence['_id']]);
    $db->communications->updateOne(['_id' => $failed['_id']], ['$set' => ['status' => 'FALHA']]);
    $error = undoFails(fn() => $occurrences->retry($coord, $failed['_id'], 'https://escola.example.org'),
        InvalidArgumentException::class, 'reenvio de aviso de falta desfeita');
    undoAssert(str_contains($error->getMessage(), 'desfeita')
        && $db->communications->countDocuments(['retry_of_id' => $failed['_id']]) === 0, 'reenvio de falta desfeita.');
    $cancelledRows = $occurrences->list('CANCELADA');
    $caioRow = array_values(array_filter($cancelledRows, static fn(array $r): bool => $r['id'] === (string)$caioAbsence['_id']))[0];
    undoAssert(count($cancelledRows) === $db->absences->countDocuments(['status' => 'CANCELADA'])
        && $caioRow['failed_count'] === 0 && $occurrences->list('PENDENTE') === [], 'filtro de faltas desfeitas.');

    // 15. Frequência do período inteiro: todas as faltas desfeitas contam presença; uma falta real continua.
    $frequency = new MongoFrequencyReport($db);
    $period = $frequency->report('2026-11-02', '2026-11-18')['summary'];
    undoAssert($period['present'] === 52 && $period['absent'] === 0 && $period['total'] === 52,
        'frequência do período: ' . json_encode($period));
    $call('2026-11-19', ['ana']);
    $control = $frequency->report('2026-11-19', '2026-11-19')['summary'];
    undoAssert($control['present'] === 3 && $control['absent'] === 1, 'falta não desfeita deixou de contar.');

    fwrite(STDOUT, "MongoAbsenceCancellationTest: fila, correção, push, envio em curso, reserva expirada, respondida, permissões, chamada e frequência passaram.\n");
} finally {
    $db->drop();
}
