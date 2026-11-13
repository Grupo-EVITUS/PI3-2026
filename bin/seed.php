#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Carrega dados FICTÍCIOS de demonstração no MongoDB (issue #25): usuários de cada perfil, período letivo, turmas,
 * alunos, responsáveis, vínculos, frequência dos últimos ~2 meses, justificativas e e-mails de ausência.
 * Os dados ficam em src/Database/DemoData.php (nomes inventados; e-mails nos domínios reservados .test).
 *
 *   php bin/seed.php              cria o que falta (idempotente: rodar de novo não duplica nada)
 *   php bin/seed.php --reset      apaga SÓ os dados de demonstração e os recria
 *   php bin/seed.php --help
 *
 * Recusa APP_ENV=production (só roda com --force --yes). Senha dos usuários: SEED_PASSWORD (mínimo de 10
 * caracteres) ou, sem ela, uma senha aleatória forte por usuário, exibida uma única vez.
 * Códigos de saída: 0 sucesso | 1 falha (conexão ou MongoDB recusou algo) | 2 uso incorreto | 3 recusado em produção
 */

use Saa\Core\Config;
use Saa\Database\DefaultData;
use Saa\Database\DemoData;
use Saa\Database\Mongo;
use Saa\Database\Schema;
use Saa\Domain\Profile;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = <<<'TXT'
Uso: php bin/seed.php [--reset] [--force --yes]

Carrega dados FICTÍCIOS de demonstração (nomes inventados, e-mails @escola.test e @exemplo.test).
Rodar de novo não duplica nada: o que já existe é reaproveitado e aparece como "já existia".

  (sem opções)   Cria o que falta. Nunca apaga nem altera dados existentes (senhas inclusive).
  --reset        Antes de recriar, apaga SOMENTE os dados de demonstração: alunos com matrícula DEMO-*
                 (e as matrículas, frequências e e-mails deles), responsáveis @exemplo.test, os usuários
                 admin@, coordenacao@, aoe@ e secretaria@escola.test e, se ficarem sem uso, as turmas
                 5A/6B/7C e o período "Ano letivo AAAA". Os demais cadastros e a auditoria não são tocados.
  --force        Necessário para rodar com APP_ENV=production (desaconselhado). Exige também --yes.
  --yes          Confirma o --force.
  -h, --help     Mostra esta ajuda.

Senhas: variável SEED_PASSWORD (mínimo de 10 caracteres) para todos os usuários criados agora; sem ela, cada
usuário recebe uma senha aleatória forte, exibida uma única vez. Usuários que já existiam mantêm a senha.
Conexão: MONGODB_URI e MONGODB_DB (ambiente ou arquivo .env). Validators e índices são conferidos antes (init_db).

TXT;

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if (!in_array($arg, ['--reset', '--force', '--yes', '--help', '-h'], true)) {
        fwrite(STDERR, "Opção desconhecida: {$arg}\n\n{$usage}");
        exit(2);
    }
}
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo $usage;
    exit(0);
}
$reset = in_array('--reset', $args, true);
$force = in_array('--force', $args, true);
$confirmed = in_array('--yes', $args, true);

if (Config::isProduction()) {
    if (!$force) {
        fwrite(STDERR, "Recusado: APP_ENV=production. Dados fictícios não devem ir para o banco de produção.\n"
            . "Se for mesmo um ambiente de demonstração, rode com --force --yes.\n");
        exit(3);
    }
    if (!$confirmed) {
        fwrite(STDERR, "Recusado: --force em APP_ENV=production exige a confirmação --yes.\n");
        exit(3);
    }
}

$password = Config::get('SEED_PASSWORD');
$password = $password === null || $password === '' ? null : $password;
if ($password !== null && mb_strlen($password, 'UTF-8') < 10) {
    fwrite(STDERR, "SEED_PASSWORD precisa ter pelo menos 10 caracteres (ou deixe vazia para gerar senhas aleatórias).\n");
    exit(2);
}

// Exibe o servidor sem usuário, senha ou opções da URI (podem conter segredos).
$uri = Config::string('MONGODB_URI', 'mongodb://127.0.0.1:27017');
$server = preg_replace('#^([A-Za-z][A-Za-z0-9+.-]*://)[^@/]*@#', '$1***@', $uri) ?? $uri;
$server = preg_replace('#\?.*$#', '', $server) ?? $server;
$databaseName = Mongo::databaseName();

echo "SAA - dados de demonstração (fictícios)\n";
echo "Servidor: {$server}\n";
echo "Banco:    {$databaseName}\n\n";

try {
    if (!Mongo::ping()) {
        fwrite(STDERR, "Falha: não foi possível conectar ao MongoDB em {$server}.\n"
            . "Verifique se o servidor está no ar e confira MONGODB_URI no ambiente ou no arquivo .env.\n");
        exit(1);
    }
    $database = Mongo::database();
    Schema::apply($database);          // garante coleções, validators e índices (idempotente)
    DefaultData::apply($database);     // modelo de e-mail ABSENCE_NOTICE
    $demo = new DemoData($database);

    if ($reset) {
        $removed = array_filter($demo->reset());
        echo $removed === []
            ? "--reset: não havia dados de demonstração para apagar.\n\n"
            : '--reset: dados de demonstração apagados (' . implode(', ', array_map(
                static fn (string $collection, int $count): string => "{$collection}: {$count}",
                array_keys($removed),
                $removed
            )) . ").\n\n";
    }

    $report = $demo->seed($password);
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha: ' . $e->getMessage() . "\n");
    exit(1);
}

// str_pad() conta bytes; os rótulos têm acentos, então o preenchimento usa mb_strlen().
$pad = static fn (string $text, int $width): string => $text . str_repeat(' ', max(0, $width - mb_strlen($text)));

echo 'Período letivo: ' . $report['period']['name'] . ' (' . $report['period']['start'] . ' a ' . $report['period']['end'] . ') - '
    . $report['period']['status'] . "\n";
echo $report['history']['from'] !== null
    ? 'Frequência: dias úteis de ' . $report['history']['from'] . ' a ' . $report['history']['to'] . "\n\n"
    : "Frequência: nenhum dia útil na janela do histórico.\n\n";

echo $pad('Item', 30) . $pad('Criados', 10) . "Já existiam\n";
echo str_repeat('-', 52) . "\n";
$totalCreated = 0;
$totalExisting = 0;
foreach ($report['counts'] as $category => $count) {
    $totalCreated += $count['created'];
    $totalExisting += $count['existing'];
    echo $pad($category, 30) . $pad((string)$count['created'], 10) . $count['existing'] . "\n";
}
echo str_repeat('-', 52) . "\n";
echo $totalCreated === 0
    ? "Nada novo: todos os dados de demonstração já existiam (nenhuma duplicata criada).\n"
    : "Resumo: {$totalCreated} itens criados agora; {$totalExisting} já existiam.\n";
foreach ($report['notes'] as $note) {
    echo "Aviso: {$note}\n";
}

echo "\nUsuários de demonstração:\n";
$shown = false;
foreach ($report['credentials'] as $credential) {
    $line = '  ' . $pad(Profile::labelFor($credential['profile']), 15) . $pad($credential['email'], 26);
    if ($credential['password'] === null) {
        $line .= 'já existia (senha mantida)';
    } elseif ($password !== null) {
        $line .= 'senha: a definida em SEED_PASSWORD';
    } else {
        $line .= 'senha: ' . $credential['password'];
        $shown = true;
    }
    echo $line . "\n";
}
if ($shown) {
    echo "\nAs senhas geradas aparecem SOMENTE agora. Guarde-as em local seguro (ou rode de novo com --reset).\n";
}
echo "\nTodos os nomes e e-mails são fictícios (domínios reservados .test: nenhum e-mail real é enviado).\n";
exit(0);
