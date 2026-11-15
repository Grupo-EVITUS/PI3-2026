<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Migration/SQLiteSnapshot.php';

$options = getopt('', ['source:', 'snapshot:', 'manifest:']);
if (!isset($options['source'], $options['snapshot'], $options['manifest'])
    || !is_string($options['source']) || !is_string($options['snapshot']) || !is_string($options['manifest'])
    || file_exists($options['manifest']) || is_link($options['manifest'])
    || realpath(dirname($options['manifest'])) === false) {
    fwrite(STDERR, "Uso: php bin/prepare_sqlite_migration.php --source=ORIGEM --snapshot=COPIA_NOVA --manifest=RELATORIO_NOVO\n");
    exit(2);
}
try {
    $report = Saa\Migration\SQLiteSnapshot::prepare($options['source'], $options['snapshot']);
    $report['snapshot_file'] = basename($options['snapshot']);
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $handle = fopen($options['manifest'], 'x');
    if ($handle === false) {
        throw new RuntimeException('Não foi possível criar o manifesto.');
    }
    try {
        fwrite($handle, $json . "\n");
    } finally {
        fclose($handle);
    }
    echo 'Prévia: ' . $report['status'] . '; manifesto: ' . $options['manifest'] . "\n";
    exit($report['status'] === 'ready_for_dry_run' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Não foi possível preparar a cópia: ' . $error->getMessage() . "\n");
    exit(1);
}
