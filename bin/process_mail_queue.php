#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Processa a fila de e-mails de ausência (email_notifications).
 *
 *   php bin/process_mail_queue.php                 processa até 50 mensagens prontas e termina
 *   php bin/process_mail_queue.php --limit=10      processa no máximo 10
 *   php bin/process_mail_queue.php --watch         fica em execução, verificando a fila a cada 5 segundos
 *   php bin/process_mail_queue.php --help
 *
 * Prontas = PENDING, ou FAILED com menos de 3 tentativas e espera vencida. Cada mensagem é reservada com
 * findOneAndUpdate (locked_at), então vários processos podem rodar ao mesmo tempo sem enviar a mesma mensagem duas
 * vezes; uma reserva com mais de 5 minutos (processo que caiu) é retomada. Usa MAIL_MODE (log | file | smtp),
 * MONGODB_URI e MONGODB_DB. Códigos de saída: 0 sucesso | 1 falha (ex.: MongoDB fora do ar) | 2 uso incorreto.
 */

use MongoDB\Database;
use Saa\Core\Container;
use Saa\Database\Mongo;
use Saa\Services\NotificationService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$usage = <<<'TXT'
Uso: php bin/process_mail_queue.php [--limit=N] [--watch] [--interval=S]

  --limit=N      Máximo de mensagens por rodada (padrão 50, de 1 a 1000).
  --watch        Não termina: processa a fila a cada --interval segundos (padrão 5). Interrompa com Ctrl+C.
  --interval=S   Intervalo entre rodadas no modo --watch (1 a 3600 segundos).
  -h, --help     Mostra esta ajuda.

TXT;

$limit = 50;
$interval = 5;
$watch = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        echo $usage;
        exit(0);
    }
    if ($arg === '--watch') {
        $watch = true;
    } elseif (preg_match('/^--limit=(\d{1,4})$/', $arg, $match) === 1 && (int)$match[1] >= 1 && (int)$match[1] <= 1000) {
        $limit = (int)$match[1];
    } elseif (preg_match('/^--interval=(\d{1,4})$/', $arg, $match) === 1 && (int)$match[1] >= 1 && (int)$match[1] <= 3600) {
        $interval = (int)$match[1];
    } else {
        fwrite(STDERR, "Opção inválida: {$arg}\n\n{$usage}");
        exit(2);
    }
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

try {
    if (!Mongo::ping()) {
        fwrite(STDERR, "Falha: não foi possível conectar ao MongoDB. Verifique MONGODB_URI no ambiente ou no arquivo .env.\n");
        exit(1);
    }
    $container = new Container();
    $container->instance(Container::class, $container);
    $container->singleton(Database::class, static fn (): Database => Mongo::database());
    /** @var NotificationService $service */
    $service = $container->get(NotificationService::class);

    do {
        $result = $service->processQueue($limit);
        if (!$watch || $result['processed'] > 0) {
            echo sprintf(
                "[%s] Mensagens processadas: %d (enviadas: %d, com falha: %d).\n",
                gmdate('Y-m-d H:i:s') . ' UTC',
                $result['processed'],
                $result['sent'],
                $result['failed']
            );
        }
        if ($watch && $result['processed'] < $limit) {
            sleep($interval);
        }
    } while ($watch);
} catch (Throwable $error) {
    fwrite(STDERR, 'Falha: ' . $error->getMessage() . "\n");
    exit(1);
}
exit(0);
