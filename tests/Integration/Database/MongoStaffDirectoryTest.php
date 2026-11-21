<?php
declare(strict_types=1);

use Saa\Database\InitialAdmin;
use Saa\Database\Mongo;
use Saa\Database\MongoStaffDirectory;
use Saa\Database\MongoStaffRegistration;
use Saa\Database\Schema;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
function rejects(callable $call, string $message): void
{
    try {
        $call();
    } catch (InvalidArgumentException) {
        return;
    }
    throw new RuntimeException($message);
}

$db = Mongo::database('saa_staff_directory_test_' . bin2hex(random_bytes(8)));
try {
    Schema::apply($db);
    $coord = InitialAdmin::create($db, 'Coordenação', 'coord@example.org', 'Senha-temporaria-2026');
    $registration = new MongoStaffRegistration($db);
    $registration->register($coord, 'Ana Souza', 'ana@example.org', 'Senha-temporaria-2026', 'AOE');
    $registration->register($coord, 'Bruno Lima', 'bruno@escola.example.org', 'Senha-temporaria-2026', 'AOE');
    $registration->register($coord, 'Carla Dias', 'carla@example.org', 'Senha-temporaria-2026', 'DIRETOR');
    $registration->register($coord, 'Dora Inativa', 'dora@example.org', 'Senha-temporaria-2026', 'AOE');
    $db->users->updateOne(['email' => 'dora@example.org'], ['$set' => ['active' => false]]);

    $directory = new MongoStaffDirectory($db);
    $names = static fn(array $rows): array => array_map(static fn(array $r): string => $r['name'], $rows);

    $all = $directory->list();
    check($names($all) === ['Ana Souza', 'Bruno Lima', 'Carla Dias', 'Coordenação', 'Dora Inativa'],
        'A listagem deve trazer todas as contas em ordem alfabética: ' . json_encode($names($all)));
    check(array_keys($all[0]) === ['id', 'name', 'email', 'profile', 'active', 'created_at']
        && preg_match('/^[a-f0-9]{24}$/', $all[0]['id']) === 1 && $all[0]['active'] === true
        && $all[0]['created_at'] !== null && $all[4]['active'] === false,
        'Os dados exibidos estão incorretos.');
    $dump = json_encode($all);
    check(!str_contains($dump, 'password') && !str_contains($dump, '$2y$') && !str_contains($dump, 'email_key'),
        'A listagem expõe hash de senha ou campos internos.');

    check($names($directory->list('ana')) === ['Ana Souza'] && $names($directory->list('  ANA  ')) === ['Ana Souza'],
        'A busca por nome deve ignorar maiúsculas e espaços nas pontas.');
    check($names($directory->list('@escola')) === ['Bruno Lima'], 'A busca por e-mail falhou.');
    check(count($directory->list('example.org')) === 5, 'A busca deve alcançar o e-mail de todas as contas.');
    // Metacaracteres de regex são texto literal.
    foreach (['.*', '(', '[a-z]+', '^A', 'a|b', '\\'] as $term) {
        check($directory->list($term) === [], 'A busca interpretou regex: ' . $term);
    }

    check($names($directory->list(profile: 'AOE')) === ['Ana Souza', 'Bruno Lima', 'Dora Inativa'], 'Filtro de perfil falhou.');
    check($names($directory->list(profile: 'DIRETOR')) === ['Carla Dias'], 'Filtro de perfil falhou.');
    check($names($directory->list(active: false)) === ['Dora Inativa'], 'Filtro de inativos falhou.');
    check(count($directory->list(active: true)) === 4, 'Filtro de ativos falhou.');
    check($names($directory->list('bruno', 'AOE', true)) === ['Bruno Lima']
        && $directory->list('bruno', 'DIRETOR') === [], 'Filtros combinados falharam.');

    rejects(fn() => $directory->list(profile: 'ADMIN'), 'Perfil arbitrário foi aceito.');
    rejects(fn() => $directory->list(profile: '$ne'), 'Operador no perfil foi aceito.');
    rejects(fn() => $directory->list(str_repeat('a', 101)), 'Busca longa foi aceita.');
    rejects(fn() => $directory->list("ana\0"), 'Byte nulo foi aceito.');
    rejects(fn() => $directory->list("\xff\xfe"), 'UTF-8 inválido foi aceito.');

    fwrite(STDOUT, "MongoStaffDirectoryTest: listagem, busca e filtros passaram.\n");
} finally {
    $db->drop();
}
