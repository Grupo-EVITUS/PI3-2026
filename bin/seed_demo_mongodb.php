<?php
declare(strict_types=1);

use Saa\Database\DemoSchoolSeed;
use Saa\Database\Mongo;

if (PHP_SAPI !== 'cli') {
    exit(2);
}
require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['target-db:', 'apply']);
$target = $options['target-db'] ?? null;
if (!is_string($target) || preg_match('/^saa_demo_[A-Za-z0-9_]{1,40}$/D', $target) !== 1
    || !array_key_exists('apply', $options) || $target === trim((string)getenv('MONGODB_DB'))) {
    fwrite(STDERR, "Uso: php bin/seed_demo_mongodb.php --target-db=saa_demo_NOME --apply\n");
    exit(2);
}
try {
    $counts = (new DemoSchoolSeed(Mongo::database($target)))->apply();
    echo json_encode(['target_db' => $target, 'counts' => $counts], JSON_THROW_ON_ERROR) . "\n";
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha na carga fictícia: ' . $error::class . "\n");
    exit(1);
}
