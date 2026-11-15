<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/Migration/SQLiteSnapshot.php';

$options = getopt('', ['snapshot:', 'manifest:', 'target-db:', 'apply']);
$targetName = $options['target-db'] ?? null;
if (!is_string($options['snapshot'] ?? null) || !is_string($options['manifest'] ?? null)
    || !is_string($targetName) || preg_match('/^saa_import_[A-Za-z0-9_]{1,40}$/', $targetName) !== 1
    || $targetName === getenv('MONGODB_DB')) {
    fwrite(STDERR, "Uso: php bin/import_sqlite_mongodb.php --snapshot=COPIA --manifest=JSON --target-db=saa_import_NOME [--apply]\n");
    exit(2);
}
try {
    $importer = new Saa\Migration\SQLiteImporter(
        $options['snapshot'], $options['manifest'], Saa\Database\Mongo::database($targetName)
    );
    $counts = $importer->run(false); // Converte e valida todos os registros antes de escrever.
    if (array_key_exists('apply', $options)) {
        $counts = $importer->run(true);
    }
    echo json_encode(['mode' => isset($options['apply']) ? 'apply' : 'dry_run',
        'target_db' => $targetName, 'counts' => $counts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Importação interrompida: ' . $error->getMessage() . "\n");
    exit(1);
}
