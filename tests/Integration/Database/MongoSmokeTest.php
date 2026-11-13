<?php
declare(strict_types=1);

use Saa\Database\Mongo;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (getenv('MONGODB_URI') === false || getenv('MONGODB_DB') === false) {
    $fail('MONGODB_URI e MONGODB_DB devem estar definidos para o smoke test.');
}

Mongo::reset();

if (!Mongo::ping()) {
    $fail('MongoDB não respondeu ao comando ping.');
}

try {
    $database = Mongo::database();
    $expectedName = trim((string) getenv('MONGODB_DB'));
    if (Mongo::databaseName() !== $expectedName) {
        $fail('A conexão selecionou um banco diferente do configurado.');
    }

    $collectionName = 'saa_smoke_' . bin2hex(random_bytes(8));
    $collection = $database->selectCollection($collectionName);

    try {
        $collection->insertOne(['_id' => 'connection-smoke', 'ok' => true]);
        $document = $collection->findOne(['_id' => 'connection-smoke']);
        if (!is_array($document) || ($document['ok'] ?? false) !== true) {
            $fail('Leitura após gravação no MongoDB não retornou o documento esperado.');
        }
    } finally {
        $collection->drop();
    }
} catch (Throwable $error) {
    $fail('Smoke test MongoDB falhou: ' . $error::class);
}

fwrite(STDOUT, "MongoSmokeTest: ping, seleção do banco e leitura/gravação passaram.\n");
