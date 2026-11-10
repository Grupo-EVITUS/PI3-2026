#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Cria ou atualiza as coleções, os validators ($jsonSchema) e os índices do SAA no MongoDB.
 *
 *   php bin/init_db.php                 cria o que falta e atualiza validators e índices (idempotente)
 *   php bin/init_db.php --drop --yes    recria TUDO do zero (apaga os dados das coleções do SAA)
 *   php bin/init_db.php --help
 *
 * Nunca apaga dados sem --drop --yes, e --drop é recusado quando APP_ENV=production.
 * Usa MONGODB_URI e MONGODB_DB (variáveis de ambiente ou arquivo .env). Códigos de saída:
 *   0 sucesso | 1 falha (conexão ou MongoDB recusou algo) | 2 uso incorreto | 3 --drop recusado em produção
 */

use Saa\Core\Config;
use Saa\Database\Collections;
use Saa\Database\DefaultData;
use Saa\Database\Mongo;
use Saa\Database\Schema;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = <<<'TXT'
Uso: php bin/init_db.php [--drop --yes]

  (sem opções)   Cria as coleções que faltam e atualiza validators e índices. Não apaga dados.
  --drop         Apaga as coleções do SAA antes de recriá-las. Exige --yes e não funciona em produção.
  --yes          Confirma o --drop.
  -h, --help     Mostra esta ajuda.

Conexão: variáveis MONGODB_URI e MONGODB_DB (ambiente ou arquivo .env).

TXT;

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if (!in_array($arg, ['--drop', '--yes', '--help', '-h'], true)) {
        fwrite(STDERR, "Opção desconhecida: {$arg}\n\n{$usage}");
        exit(2);
    }
}
if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo $usage;
    exit(0);
}
$drop = in_array('--drop', $args, true);
$confirmed = in_array('--yes', $args, true);

// Exibe o servidor sem usuário, senha ou opções da URI (podem conter segredos).
$uri = Config::string('MONGODB_URI', 'mongodb://127.0.0.1:27017');
$server = preg_replace('#^([A-Za-z][A-Za-z0-9+.-]*://)[^@/]*@#', '$1***@', $uri) ?? $uri;
$server = preg_replace('#\?.*$#', '', $server) ?? $server;
$databaseName = Mongo::databaseName();

if ($drop && Config::isProduction()) {
    fwrite(STDERR, "Recusado: --drop não é permitido com APP_ENV=production.\n");
    exit(3);
}
if ($drop && !$confirmed) {
    fwrite(STDERR, "Recusado: --drop apaga TODOS os dados das coleções do SAA no banco '{$databaseName}'.\n"
        . "Repita o comando com --yes para confirmar: php bin/init_db.php --drop --yes\n");
    exit(2);
}

echo "SAA - inicialização do banco de dados (MongoDB)\n";
echo "Servidor: {$server}\n";
echo "Banco:    {$databaseName}\n\n";

try {
    if (!Mongo::ping()) {
        fwrite(STDERR, "Falha: não foi possível conectar ao MongoDB em {$server}.\n"
            . "Verifique se o servidor está no ar e confira MONGODB_URI no ambiente ou no arquivo .env.\n");
        exit(1);
    }
    $database = Mongo::database();

    if ($drop) {
        $existing = [];
        foreach ($database->listCollectionNames(['filter' => ['name' => ['$in' => Collections::all()]]]) as $name) {
            $existing[] = (string)$name;
        }
        foreach ($existing as $name) {
            $database->dropCollection($name);
        }
        echo $existing === []
            ? "--drop: nenhuma coleção do SAA existia.\n\n"
            : '--drop: coleções removidas (' . count($existing) . '): ' . implode(', ', $existing) . ".\n\n";
    }

    $report = Schema::apply($database);
    $defaults = DefaultData::apply($database); // dados padrão (modelo de e-mail ABSENCE_NOTICE); idempotente
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha: ' . $e->getMessage() . "\n");
    exit(1);
}

// str_pad() conta bytes; os rótulos têm acentos, então o preenchimento usa mb_strlen().
$pad = static fn (string $text, int $width): string => $text . str_repeat(' ', max(0, $width - mb_strlen($text)));

echo $pad('Coleção', 22) . $pad('Situação', 12) . $pad('Índices', 9) . $pad('Novos', 7) . "Recriados\n";
echo str_repeat('-', 58) . "\n";
$totals = ['created' => 0, 'updated' => 0, 'indexes' => 0, 'new' => 0, 'rebuilt' => 0];
$extras = [];
foreach ($report as $row) {
    $totals[$row['action']]++;
    $totals['indexes'] += $row['indexes_total'];
    $totals['new'] += count($row['indexes_created']);
    $totals['rebuilt'] += count($row['indexes_rebuilt']);
    if ($row['indexes_extra'] !== []) {
        $extras[] = $row['collection'] . ': ' . implode(', ', $row['indexes_extra']);
    }
    echo $pad($row['collection'], 22)
        . $pad($row['action'] === 'created' ? 'criada' : 'atualizada', 12)
        . $pad((string)$row['indexes_total'], 9)
        . $pad((string)count($row['indexes_created']), 7)
        . count($row['indexes_rebuilt']) . "\n";
}
echo str_repeat('-', 58) . "\n";
echo sprintf(
    "Resumo: %d coleções (%d criadas, %d atualizadas); %d índices (%d novos, %d recriados).\n",
    count($report),
    $totals['created'],
    $totals['updated'],
    $totals['indexes'],
    $totals['new'],
    $totals['rebuilt']
);
if ($extras !== []) {
    echo "Índices fora do modelo (mantidos, nada foi removido):\n";
    foreach ($extras as $line) {
        echo "  - {$line}\n";
    }
}
echo $defaults === [] ? "Dados padrão: já existentes (nada alterado).\n" : 'Dados padrão criados: ' . implode(', ', $defaults) . ".\n";
echo $drop ? "Banco recriado do zero.\n" : "Nenhum dado foi apagado.\n";
exit(0);
