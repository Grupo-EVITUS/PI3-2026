<?php
declare(strict_types=1);

use Saa\Database\HealthCheck;
use Saa\Database\Mongo;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este health check é interno e só pode ser executado no terminal.\n");
    exit(2);
}

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    if (!Mongo::ping()) {
        $report = ['status' => 'not_ready', 'checks' => ['connection' => ['status' => 'failed']]];
    } else {
        $report = HealthCheck::inspect(Mongo::database());
        $report['checks'] = ['connection' => ['status' => 'ok']] + $report['checks'];
    }
} catch (Throwable) {
    // Não expõe URI, nomes de banco, documentos nem mensagens do driver.
    $report = ['status' => 'not_ready', 'checks' => ['connection' => ['status' => 'failed']]];
}

fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
exit($report['status'] === 'ready' ? 0 : 1);
