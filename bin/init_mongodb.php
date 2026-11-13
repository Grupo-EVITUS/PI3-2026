<?php
declare(strict_types=1);

use Saa\Database\Mongo;
use Saa\Database\Schema;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    Schema::apply(Mongo::database());
    fwrite(STDOUT, "Schema e índices MongoDB aplicados; nenhum dado de demonstração ou e-mail criado.\n");
} catch (Throwable $error) {
    // Não expor URI, documentos, hashes ou mensagens do driver.
    fwrite(STDERR, "Falha na inicialização MongoDB: " . $error::class . "\n");
    exit(1);
}
