<?php
declare(strict_types=1);

// Front controller: todas as requisições dinâmicas passam por aqui (DocumentRoot = public/).

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Dependências ausentes. Execute: composer install\n";
    exit;
}

require $autoload;

Saa\Core\App::run();
