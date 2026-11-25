<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoPasswordReset;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\MongoUserAuthenticator;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function expectThrows(string $class, callable $call, string $message): void
{
    try {
        $call();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw new RuntimeException($message . ' (lançou ' . $error::class . ': ' . $error->getMessage() . ')');
    }
    throw new RuntimeException($message);
}
function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

const BASE = 'https://escola.example.org';
const OLD_PASSWORD = 'Senha-temporaria-2026';
const NEW_PASSWORD = 'Outra-senha-forte-2026';

$db = Mongo::database('saa_password_reset_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $coord = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', OLD_PASSWORD);
    $registration = new MongoStaffRegistration($db);
    $aoe = $registration->register($coord, 'Ana AOE', 'aoe@example.org', OLD_PASSWORD, 'AOE');
    $director = $registration->register($coord, 'Diretor', 'diretor@example.org', OLD_PASSWORD, 'DIRETOR');
    $inactive = $registration->register($coord, 'Inativa', 'inativa@example.org', OLD_PASSWORD, 'AOE');
    $db->users->updateOne(['_id' => $inactive], ['$set' => ['active' => false]]);

    $service = new MongoPasswordReset($db);
    $tokenOf = static function (ObjectId $userId) use ($db): string {
        $doc = $db->password_resets->findOne(['user_id' => $userId], ['sort' => ['created_at' => -1, '_id' => -1]]);
        check($doc !== null && preg_match('~/redefinir-senha/([A-Za-z0-9_-]{43})~', $doc['mail']['body'], $m) === 1,
            'O e-mail pendente não contém o link.');
        return $m[1];
    };

    // Pedidos que não geram nada, com resposta indistinguível e tempo mínimo.
    $started = microtime(true);
    foreach (['desconhecido@example.org', 'diretor@example.org', 'inativa@example.org', 'nao-e-email', '',
        ['aoe@example.org'], null, str_repeat('a', 300) . '@example.org'] as $email) {
        $service->request($email, BASE);
    }
    check($db->password_resets->countDocuments() === 0, 'Conta inexistente, inativa, Diretor ou entrada inválida gerou pedido.');
    check(microtime(true) - $started >= 8 * 0.28, 'O pedido sem conta respondeu rápido demais (revela existência).');
    expectThrows(InvalidArgumentException::class, fn() => $service->request('aoe@example.org', 'ftp://x'),
        'Endereço público inválido foi aceito.');

    // Pedido válido (e-mail sem diferenciar maiúsculas).
    $started = microtime(true);
    $service->request('AOE@Example.org', BASE . '/');
    check(microtime(true) - $started >= 0.28, 'O pedido com conta respondeu rápido demais.');
    $doc = $db->password_resets->findOne();
    $token = $tokenOf($aoe);
    $ttl = $doc['expires_at']->toDateTime()->getTimestamp() - time();
    check($db->password_resets->countDocuments() === 1 && $doc['mail_status'] === 'AGUARDANDO'
        && $doc['mail']['recipient'] === 'aoe@example.org' && $doc['token_hash'] === hash('sha256', $token)
        && $ttl > 3590 && $ttl <= 3600
        && str_contains($doc['mail']['body'], BASE . '/redefinir-senha/' . $token)
        && !str_contains($doc['mail']['body'], BASE . '//'),
        'O pedido não gravou token (hash), validade e e-mail corretamente.');
    check($db->operation_logs->countDocuments(['action' => 'SENHA_REDEFINICAO_SOLICITADA',
        'entity_id' => (string)$aoe]) === 1, 'Pedido não foi auditado.');
    $logs = json_encode(iterator_to_array($db->operation_logs->find(), false));
    check(!str_contains($logs, $token) && !str_contains($logs, 'aoe@example.org'), 'A auditoria expõe token ou e-mail.');

    // Limite de um pedido a cada 5 minutos por conta.
    $service->request('aoe@example.org', BASE);
    check($db->password_resets->countDocuments() === 1, 'O limite por conta não foi aplicado.');

    $found = $service->find($token);
    check($found !== null && $found['name'] === 'Ana AOE', 'Link válido não foi reconhecido.');
    check($service->find('curto') === null && $service->find(str_repeat('a', 43)) === null,
        'Link inexistente foi reconhecido.');

    // Entrega pelo worker: retentativa com espera, depois sucesso e limpeza do token no banco.
    check($service->deliver(static function (): void { throw new RuntimeException('SMTP fora'); }) === 1,
        'A primeira tentativa não foi contada.');
    $doc = $db->password_resets->findOne();
    check($doc['mail_status'] === 'AGUARDANDO' && (int)$doc['attempts'] === 1
        && $doc['next_attempt_at'] instanceof UTCDateTime && $doc['mail']['body'] !== '',
        'A falha transitória não foi reagendada.');
    check($service->deliver(static function (): void { throw new LogicException('Antes do prazo.'); }) === 0,
        'A retentativa ignorou a espera.');
    $db->password_resets->updateOne([], ['$set' => ['next_attempt_at' => new UTCDateTime((time() - 5) * 1000)]]);
    $sent = [];
    check($service->deliver(static function (array $mail) use (&$sent): void { $sent[] = $mail; }) === 1, 'Entrega não ocorreu.');
    $doc = $db->password_resets->findOne();
    check(count($sent) === 1 && $sent[0]['recipient'] === 'aoe@example.org'
        && str_contains($sent[0]['body'], $token) && $doc['mail_status'] === 'CONCLUIDO' && $doc['mail']['body'] === ''
        && $service->deliver(static function (): void { throw new LogicException('Reenvio indevido.'); }) === 0
        && $service->find($token) !== null
        && $db->operation_logs->countDocuments(['action' => 'SENHA_EMAIL_ENVIADO']) === 1,
        'Entrega, limpeza do corpo ou auditoria falhou.');

    // Redefinição: política de senha, link inválido, uso único.
    foreach (['curta', str_repeat('a', 73), "senha-com-nul\0-byte", 12345678901, null] as $bad) {
        expectThrows(InvalidArgumentException::class, fn() => $service->reset($token, $bad), 'Senha fora da política foi aceita.');
    }
    expectThrows(DomainException::class, fn() => $service->reset('x', NEW_PASSWORD), 'Token malformado foi aceito.');
    expectThrows(DomainException::class, fn() => $service->reset(str_repeat('b', 43), NEW_PASSWORD), 'Token desconhecido foi aceito.');
    $auth = new MongoUserAuthenticator($db->users);
    check($auth->authenticate('aoe@example.org', OLD_PASSWORD) !== null, 'Tentativas recusadas não podem alterar a senha.');
    $service->reset($token, NEW_PASSWORD);
    check($auth->authenticate('aoe@example.org', NEW_PASSWORD) !== null
        && $auth->authenticate('aoe@example.org', OLD_PASSWORD) === null
        && $db->password_resets->findOne()['used_at'] instanceof UTCDateTime
        && $db->operation_logs->countDocuments(['action' => 'SENHA_REDEFINIDA', 'entity_id' => (string)$aoe]) === 1,
        'A senha não foi trocada, o link não foi consumido ou faltou auditoria.');
    expectThrows(DomainException::class, fn() => $service->reset($token, 'Terceira-senha-2026'), 'O link foi usado duas vezes.');
    check($service->find($token) === null && $auth->authenticate('aoe@example.org', NEW_PASSWORD) !== null,
        'Link usado continua válido.');

    // Novo pedido revoga o anterior (e apaga o link guardado); só o mais recente vale.
    $service->request('coord@example.org', BASE);
    $first = $tokenOf($coord);
    $db->password_resets->updateOne(['user_id' => $coord],
        ['$set' => ['created_at' => new UTCDateTime((time() - 600) * 1000)]]);
    $service->request('coord@example.org', BASE);
    $second = $tokenOf($coord);
    $revoked = $db->password_resets->findOne(['token_hash' => hash('sha256', $first)]);
    check($first !== $second && $revoked['revoked_at'] instanceof UTCDateTime && $revoked['mail']['body'] === ''
        && $service->find($first) === null && $service->find($second) !== null,
        'O novo pedido não revogou o anterior.');
    expectThrows(DomainException::class, fn() => $service->reset($first, NEW_PASSWORD), 'Link revogado foi aceito.');
    $sent = [];
    $service->deliver(static function (array $mail) use (&$sent): void { $sent[] = $mail; });
    check(count($sent) === 1 && str_contains($sent[0]['body'], $second) && !str_contains($sent[0]['body'], $first),
        'O worker enviou link revogado.');

    // Falha definitiva após 3 tentativas: o corpo (com o token) é apagado.
    $db->password_resets->updateMany(['user_id' => $aoe], ['$set' => ['created_at' => new UTCDateTime((time() - 900) * 1000)]]);
    $service->request('aoe@example.org', BASE);
    $pending = $db->password_resets->findOne(['user_id' => $aoe, 'mail_status' => 'AGUARDANDO']);
    check($pending !== null, 'Novo pedido da AOE não foi criado.');
    for ($i = 0; $i < 3; $i++) {
        $service->deliver(static function (): void { throw new RuntimeException('SMTP fora'); });
        $db->password_resets->updateOne(['_id' => $pending['_id']], ['$set' => ['next_attempt_at' => new UTCDateTime((time() - 5) * 1000)]]);
    }
    $pending = $db->password_resets->findOne(['_id' => $pending['_id']]);
    check($pending['mail_status'] === 'FALHA' && $pending['mail']['body'] === ''
        && $db->operation_logs->countDocuments(['action' => 'SENHA_EMAIL_FALHA']) === 1,
        'Falha definitiva não limpou o corpo nem foi auditada.');

    // Expirado, conta desativada e perfil Diretor não redefinem nem recebem e-mail.
    $db->password_resets->updateMany([], ['$set' => ['expires_at' => new UTCDateTime((time() - 10) * 1000)]]);
    check($service->find($second) === null, 'Link expirado foi aceito.');
    expectThrows(DomainException::class, fn() => $service->reset($second, NEW_PASSWORD), 'Link expirado redefiniu a senha.');
    $db->password_resets->deleteMany([]);
    $forged = 'Zz' . str_repeat('a', 41);
    $db->password_resets->insertOne([
        '_id' => new ObjectId(), 'user_id' => $director, 'token_hash' => hash('sha256', $forged),
        'created_at' => new UTCDateTime(), 'expires_at' => new UTCDateTime((time() + 600) * 1000),
        'mail_status' => 'AGUARDANDO', 'mail' => ['recipient' => 'diretor@example.org', 'subject' => 's', 'body' => 'b'],
    ]);
    check($service->find($forged) === null, 'Perfil Diretor redefiniu senha.');
    expectThrows(DomainException::class, fn() => $service->reset($forged, NEW_PASSWORD), 'Perfil Diretor redefiniu senha.');
    $db->users->updateOne(['_id' => $aoe], ['$set' => ['active' => false]]);
    $db->password_resets->deleteMany([]);
    $inactiveToken = 'Yy' . str_repeat('b', 41);
    $db->password_resets->insertOne([
        '_id' => new ObjectId(), 'user_id' => $aoe, 'token_hash' => hash('sha256', $inactiveToken),
        'created_at' => new UTCDateTime(), 'expires_at' => new UTCDateTime((time() + 600) * 1000),
        'mail_status' => 'AGUARDANDO', 'mail' => ['recipient' => 'aoe@example.org', 'subject' => 's', 'body' => 'b'],
    ]);
    check($service->find($inactiveToken) === null, 'Conta desativada redefiniu senha.');

    fwrite(STDOUT, "MongoPasswordResetTest: pedido, entrega, uso único, revogação e perfis passaram.\n");
} finally {
    $db->drop();
}
