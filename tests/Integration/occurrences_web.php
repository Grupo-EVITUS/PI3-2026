<?php
declare(strict_types=1);

/**
 * Percurso HTTP das telas de acompanhamento no modo MongoDB (SAA_WEB_MODE=mongo_staff):
 * lista de ocorrências com filtros, detalhe da ausência, reenvio de comunicação com falha e permissões.
 * A falha de entrega é simulada direto no banco, como o worker de e-mail a registraria.
 */

use MongoDB\BSON\ObjectId;
use Saa\Database\Mongo;
use Saa\Database\MongoAttendance;
use Saa\Database\MongoSchoolDirectory;
use Saa\Database\MongoStaffRegistration;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/web_client.php';

$base = rtrim((string)getenv('PUBLIC_BASE_URL'), '/');
$password = (string)getenv('INITIAL_ADMIN_PASSWORD');
if ($base === '' || $password === '') {
    fwrite(STDERR, "Defina PUBLIC_BASE_URL e INITIAL_ADMIN_PASSWORD.\n");
    exit(1);
}

function occurrencesCheck(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('occurrences_web: ' . $message);
    }
}

function signIn(string $base, string $email, string $password): array
{
    $jar = [];
    [, $page] = browse($jar, $base . '/login');
    [$status, $page] = browse($jar, $base . '/login', ['email' => $email, 'password' => $password,
        'csrf_token' => csrfFrom($page)]);
    occurrencesCheck($status === 200 && str_contains($page, 'Ocorrências de ausência'),
        "login de $email não abriu as ocorrências.");
    return $jar;
}

// Cenário: uma ausência cujo e-mail ao responsável esgotou as tentativas.
$db = Mongo::database();
$coordId = $db->users->findOne(['email' => getenv('INITIAL_ADMIN_EMAIL')])['_id'];
$staff = new MongoStaffRegistration($db);
$staff->register($coordId, 'AOE Ocorrências', 'aoe.ocorrencias@example.org', $password, 'AOE');
$staff->register($coordId, 'Direção Ocorrências', 'diretor.ocorrencias@example.org', $password, 'DIRETOR');
$aoeId = $db->users->findOne(['email' => 'aoe.ocorrencias@example.org'])['_id'];
$directory = new MongoSchoolDirectory($db);
$class = $directory->createClass($coordId, 'Turma Ocorrências CI', '8º ano', 2026, 'Tarde');
$student = $directory->createStudent($coordId, 'Aluno Ocorrência CI', 'OCORR-CI', $class);
$directory->createGuardian($coordId, 'Responsável Ocorrência CI', 'resp.ocorrencia@example.org', 'Pai', [$student]);
(new MongoAttendance($db))->record($aoeId, $class, [(string)$student => 'AUSENTE'], '2026-11-12', $base);
$absence = $db->absences->findOne(['student_id' => $student]);
$failed = $db->communications->findOne(['absence_id' => $absence['_id']])['_id'];
$db->communications->updateOne(['_id' => $failed],
    ['$set' => ['status' => 'FALHA', 'failure_detail' => 'Falha na entrega SMTP.']]);
$detailUrl = $base . '/ausencias/' . $absence['_id'];

// Visitante sem login vai para o login.
$guest = [];
[$status, $page] = browse($guest, $base . '/ocorrencias');
occurrencesCheck($status === 200 && str_contains($page, 'name="password"'), 'ocorrências abertas sem login.');

// Direção: só consulta.
$director = signIn($base, 'diretor.ocorrencias@example.org', $password);
[$status, $page] = browse($director, $base . '/ocorrencias');
occurrencesCheck($status === 200 && str_contains($page, 'Aluno Ocorrência CI') && str_contains($page, '1 falha(s)')
    && str_contains($page, 'href="/ausencias/' . $absence['_id'] . '"'), 'lista sem a ausência ou sem a falha.');
[, $page] = browse($director, $base . '/ocorrencias?status=RESPONDIDA');
occurrencesCheck(!str_contains($page, 'Aluno Ocorrência CI'), 'filtro de respondidas mostrou pendente.');
[, $page] = browse($director, $base . '/ocorrencias?status=PENDENTE');
occurrencesCheck(str_contains($page, 'Aluno Ocorrência CI'), 'filtro de pendentes não mostrou a ausência.');
[$status, $page] = browse($director, $detailUrl);
occurrencesCheck($status === 200 && str_contains($page, 'Responsável Ocorrência CI')
    && str_contains($page, 'Falha na entrega SMTP.') && str_contains($page, 'Turma Ocorrências CI')
    && str_contains($page, 'AOE Ocorrências') && !str_contains($page, 'action="/comunicacoes/reenvio"'),
    'detalhe para a direção incorreto (ou com botão de reenvio).');
occurrencesCheck(browse($director, $base . '/comunicacoes/reenvio',
    ['communication_id' => (string)$failed, 'csrf_token' => csrfFrom($page)])[0] === 403, 'direção conseguiu reenviar.');
occurrencesCheck(browse($director, $base . '/ausencias/' . new ObjectId())[0] === 404, 'ausência inexistente sem 404.');
occurrencesCheck(browse($director, $base . '/ausencias/123')[0] === 404, 'identificador inválido sem 404.');

// AOE: reenvia uma vez; o segundo clique é recusado.
$aoe = signIn($base, 'aoe.ocorrencias@example.org', $password);
[$status, $page] = browse($aoe, $detailUrl);
occurrencesCheck($status === 200 && str_contains($page, 'action="/comunicacoes/reenvio"'), 'AOE sem botão de reenvio.');
$form = ['communication_id' => (string)$failed, 'csrf_token' => csrfFrom($page)];
occurrencesCheck(browse($aoe, $base . '/comunicacoes/reenvio', ['communication_id' => (string)$failed])[0] === 400,
    'reenvio sem CSRF aceito.');
[$status, $page] = browse($aoe, $base . '/comunicacoes/reenvio', $form);
occurrencesCheck($status === 200 && str_contains($page, 'Novo envio colocado na fila.')
    && str_contains($page, 'reenvio') && !str_contains($page, 'action="/comunicacoes/reenvio"'),
    'reenvio não voltou ao detalhe com a nova comunicação.');
occurrencesCheck($db->communications->countDocuments(['retry_of_id' => $failed, 'status' => 'PENDENTE']) === 1
    && $db->mail_queue->countDocuments(['recipient' => 'resp.ocorrencia@example.org']) === 2,
    'reenvio não gerou nova comunicação e novo e-mail.');
[$status, $page] = browse($aoe, $base . '/comunicacoes/reenvio', $form);
occurrencesCheck($status === 200 && str_contains($page, 'Esta comunicação já foi reenviada.')
    && $db->communications->countDocuments(['retry_of_id' => $failed]) === 1, 'reenvio duplicado aceito.');

// Usuário desativado perde o acesso mesmo com a sessão aberta.
$db->users->updateOne(['_id' => $aoeId], ['$set' => ['active' => false]]);
occurrencesCheck(browse($aoe, $base . '/ocorrencias')[0] === 403, 'usuário desativado ainda vê as ocorrências.');

fwrite(STDOUT, "occurrences_web: lista, filtros, detalhe, reenvio único e permissões passaram.\n");
