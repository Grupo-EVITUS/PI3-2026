<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoJustification;
use Saa\Database\MongoOccurrences;
use Saa\Database\MongoPushSubscriptions;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\Schema;
use Saa\Push\WebPush;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Painel de ocorrências, detalhe da ausência e reenvio de comunicação que falhou.
function occurrenceAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('MongoOccurrencesTest: ' . $message);
    }
}

function occurrenceFails(callable $work, string $exception, string $message): void
{
    try {
        $work();
    } catch (Throwable $error) {
        occurrenceAssert($error instanceof $exception, $message . ' (' . $error::class . ': ' . $error->getMessage() . ')');
        return;
    }
    throw new RuntimeException('MongoOccurrencesTest: aceitou ' . $message);
}

putenv('PUSH_ALLOWED_ENDPOINT_PREFIXES');
$db = Mongo::database('saa_occurrences_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $coord = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $staff = new MongoStaffRegistration($db);
    $staff->register($coord, 'AOE', 'aoe@example.org', 'Senha-temporaria-2026', 'AOE');
    $staff->register($coord, 'Direção', 'diretor@example.org', 'Senha-temporaria-2026', 'DIRETOR');
    $aoe = $db->users->findOne(['email' => 'aoe@example.org'])['_id'];
    $director = $db->users->findOne(['email' => 'diretor@example.org'])['_id'];
    $directory = new MongoSchoolDirectory($db);
    $class = $directory->createClass($coord, 'Turma A', '5º ano', 2026, 'Manhã');
    $ana = $directory->createStudent($coord, 'Ana', 'OC-1', $class);
    $bruno = $directory->createStudent($coord, 'Bruno', 'OC-2', $class);
    $mother = $directory->createGuardian($coord, 'Mãe da Ana', 'mae@example.org', 'Mãe', [$ana]);
    $father = $directory->createGuardian($coord, 'Pai da Ana', 'pai@example.org', 'Pai', [$ana]);
    $attendance = new MongoAttendance($db);
    $attendance->record($aoe, $class, [(string)$ana => 'AUSENTE', (string)$bruno => 'PRESENTE'],
        '2026-11-10', 'https://escola.example.org');
    $attendance->record($aoe, $class, [(string)$ana => 'PRESENTE', (string)$bruno => 'AUSENTE'],
        '2026-11-11', 'https://escola.example.org');
    $directory->updateClass($coord, $class, 'Turma A renomeada', '5º ano', 2026, 'Manhã');
    $anaAbsence = $db->absences->findOne(['student_id' => $ana])['_id'];
    // Resultado do worker de e-mail: a mãe recebeu, o pai esgotou as tentativas.
    $db->communications->updateOne(['guardian_id' => $mother],
        ['$set' => ['status' => 'ENVIADO', 'sent_at' => new UTCDateTime()]]);
    $db->communications->updateOne(['guardian_id' => $father],
        ['$set' => ['status' => 'FALHA', 'failure_detail' => 'Falha na entrega SMTP.']]);
    $failed = $db->communications->findOne(['guardian_id' => $father])['_id'];

    $occurrences = new MongoOccurrences($db);
    $rows = $occurrences->list();
    occurrenceAssert(count($rows) === 2 && $rows[0]['student_name'] === 'Bruno' && $rows[1]['student_name'] === 'Ana',
        'lista deve vir da ausência mais recente para a mais antiga.');
    occurrenceAssert($rows[1]['class_name'] === 'Turma A' && $rows[1]['communication_count'] === 2
        && $rows[1]['failed_count'] === 1 && $rows[1]['reason'] === null && $rows[1]['id'] === (string)$anaAbsence,
        'linha da ausência com totais e turma da época incorretos.');
    occurrenceAssert($rows[0]['communication_count'] === 0, 'aluno sem responsável não tem comunicação.');
    occurrenceAssert(count($occurrences->list('PENDENTE')) === 2 && $occurrences->list('RESPONDIDA') === []
        && count($occurrences->list('QUALQUER')) === 2, 'filtro por status incorreto.');

    $detail = $occurrences->detail($anaAbsence);
    $byGuardian = array_column($detail['communications'], null, 'guardian_name');
    occurrenceAssert($detail['absence']['student_name'] === 'Ana' && $detail['absence']['grade'] === '5º ano'
        && $detail['absence']['registered_by_name'] === 'AOE' && $detail['justification'] === null
        && $detail['absence']['class_name'] === 'Turma A', 'detalhe da ausência incorreto.');
    occurrenceAssert($byGuardian['Pai da Ana']['can_retry'] && !$byGuardian['Mãe da Ana']['can_retry']
        && $byGuardian['Pai da Ana']['channel'] === 'EMAIL', 'reenvio deve aparecer só na comunicação com falha.');
    occurrenceAssert($occurrences->detail(new ObjectId()) === null, 'ausência inexistente deve dar null.');

    // Reenvio: só equipe AOE/Coordenação ativa, só comunicação com falha, uma vez por falha.
    occurrenceFails(fn() => $occurrences->retry($director, $failed, 'https://escola.example.org'),
        InvalidArgumentException::class, 'reenvio pela direção');
    occurrenceFails(fn() => $occurrences->retry($aoe, new ObjectId($byGuardian['Mãe da Ana']['id']),
        'https://escola.example.org'),
        InvalidArgumentException::class, 'reenvio de comunicação entregue');
    occurrenceFails(fn() => $occurrences->retry($aoe, $failed, 'javascript:alert(1)'),
        InvalidArgumentException::class, 'endereço público inválido');
    occurrenceAssert($occurrences->retry($aoe, $failed, 'https://escola.example.org/') == $anaAbsence,
        'reenvio deve devolver a ausência.');
    $retry = $db->communications->findOne(['retry_of_id' => $failed]);
    $job = $db->mail_queue->findOne(['communication_id' => $retry['_id'] ?? null]);
    occurrenceAssert($retry !== null && $retry['status'] === 'PENDENTE' && $retry['channel'] === 'EMAIL'
        && $retry['email_to'] === 'pai@example.org' && $job !== null
        && str_contains($job['body'], 'turma Turma A em 10/11/2026')
        && preg_match('#https://escola\.example\.org/justificar/[A-Za-z0-9_-]{43}\n#', $job['body']) === 1,
        'reenvio deve gerar nova comunicação, novo link e e-mail com a turma da época.');
    occurrenceAssert($db->communications->findOne(['_id' => $failed])['retried_at'] instanceof UTCDateTime
        && $db->operation_logs->countDocuments(['action' => 'COMUNICACAO_REENVIO_SOLICITADO',
            'user_id' => $aoe, 'entity_id' => (string)$failed]) === 1, 'reenvio sem marca ou sem auditoria.');
    occurrenceFails(fn() => $occurrences->retry($coord, $failed, 'https://escola.example.org'),
        DomainException::class, 'segundo reenvio da mesma falha');
    $detail = $occurrences->detail($anaAbsence);
    occurrenceAssert(count($detail['communications']) === 3 && !$detail['communications'][1]['can_retry']
        && $detail['communications'][2]['is_retry'], 'detalhe após reenvio incorreto.');

    // O reenvio também usa push quando o responsável ativou o celular depois da falha.
    $subscriptions = new MongoPushSubscriptions($db);
    $subscriptions->subscribeWithInvite($subscriptions->createInvite($coord, $father), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/pai', 'expirationTime' => null,
        'keys' => ['p256dh' => WebPush::base64UrlEncode(WebPush::generateKeyPair()['public']),
            'auth' => WebPush::base64UrlEncode(random_bytes(16))],
    ]);
    $db->communications->updateOne(['_id' => $retry['_id']], ['$set' => ['status' => 'FALHA']]);
    $occurrences->retry($coord, $retry['_id'], 'https://escola.example.org');
    $pushRetry = $db->communications->findOne(['retry_of_id' => $retry['_id']]);
    $pushJob = $db->push_queue->findOne(['communication_id' => $pushRetry['_id'] ?? null]);
    occurrenceAssert($pushRetry['channel'] === 'PUSH' && $pushJob !== null
        && $pushJob['fallback_email']['recipient'] === 'pai@example.org'
        && $db->mail_queue->countDocuments(['communication_id' => $pushRetry['_id']]) === 0,
        'reenvio deve ir por push, guardando o e-mail de reserva.');

    // Depois da justificativa, a ausência aparece como respondida e o reenvio deixa de fazer sentido.
    $motherJob = $db->mail_queue->findOne(['recipient' => 'mae@example.org']);
    preg_match('~/justificar/([A-Za-z0-9_-]{43})~', $motherJob['body'], $token);
    (new MongoJustification($db))->submit($token[1], 'DOENCA', '');
    $answered = $occurrences->list('RESPONDIDA');
    occurrenceAssert(count($answered) === 1 && $answered[0]['reason'] === 'DOENCA'
        && $answered[0]['submitted_at'] !== null, 'ausência respondida fora do filtro.');
    $detail = $occurrences->detail($anaAbsence);
    occurrenceAssert($detail['absence']['status'] === 'RESPONDIDA'
        && $detail['justification']['guardian_name'] === 'Mãe da Ana', 'justificativa ausente no detalhe.');
    $db->communications->updateOne(['_id' => $pushRetry['_id']], ['$set' => ['status' => 'FALHA']]);
    occurrenceFails(fn() => $occurrences->retry($aoe, $pushRetry['_id'], 'https://escola.example.org'),
        DomainException::class, 'reenvio de ausência já justificada');
    occurrenceAssert(!isset($db->communications->findOne(['_id' => $pushRetry['_id']])['retried_at']),
        'reenvio recusado não pode deixar marca (transação desfeita).');

    // Responsável arquivado não recebe reenvio; usuário desativado perde o acesso na hora.
    $db->absences->updateOne(['_id' => $anaAbsence], ['$set' => ['status' => 'PENDENTE']]);
    $directory->archive($coord, 'responsavel', $father);
    occurrenceFails(fn() => $occurrences->retry($aoe, $pushRetry['_id'], 'https://escola.example.org'),
        InvalidArgumentException::class, 'reenvio a responsável arquivado');
    $db->users->updateOne(['_id' => $aoe], ['$set' => ['active' => false]]);
    occurrenceFails(fn() => $occurrences->retry($aoe, $pushRetry['_id'], 'https://escola.example.org'),
        InvalidArgumentException::class, 'reenvio por usuário desativado');
    fwrite(STDOUT, "MongoOccurrencesTest: lista, filtros, detalhe e reenvio (e-mail, push e travas) passaram.\n");
} finally {
    $db->drop();
}
