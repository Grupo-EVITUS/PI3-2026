<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Ambiente de teste. Os testes NUNCA tocam o banco de desenvolvimento: cada classe de teste de
// integração cria um banco temporário "saa_test_<hex>" e o remove ao final (ver MongoTestCase).
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
