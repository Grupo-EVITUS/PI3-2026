<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use RuntimeException;
use Throwable;

/**
 * Cliente MongoDB aditivo para a etapa 2. Não altera o runtime SQLite nem realiza dual-write.
 * A URI nunca é incluída em mensagens de erro ou logs.
 */
final class Mongo
{
    /** @var array{root: string, document: string, array: string} */
    public const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private static ?Client $client = null;
    private static ?string $databaseName = null;
    private static ?string $databaseOverride = null;

    public static function client(): Client
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $uri = self::requiredEnv('MONGODB_URI');
        $database = self::requiredEnv('MONGODB_DB');
        $timeoutRaw = getenv('MONGODB_TIMEOUT_MS');
        $timeout = $timeoutRaw === false || $timeoutRaw === '' ? 5000 : filter_var($timeoutRaw, FILTER_VALIDATE_INT);
        if (!is_int($timeout) || $timeout < 500 || $timeout > 60000) {
            throw new RuntimeException('MONGODB_TIMEOUT_MS deve ser um inteiro entre 500 e 60000.');
        }

        try {
            $client = new Client(
                $uri,
                [
                    'appname' => 'saa-php',
                    'serverSelectionTimeoutMS' => $timeout,
                    'connectTimeoutMS' => $timeout,
                ],
                ['typeMap' => self::TYPE_MAP]
            );
            $client->selectDatabase('admin')->command(['ping' => 1])->toArray();
            self::$client = $client;
            self::$databaseName = $database;
            return $client;
        } catch (Throwable) {
            self::$client = null;
            self::$databaseName = null;
            throw new RuntimeException('Conexão com MongoDB indisponível; verifique a configuração e o serviço.');
        }
    }

    public static function databaseName(): string
    {
        self::client();
        return self::$databaseOverride ?? self::$databaseName ?? throw new RuntimeException('Banco MongoDB não configurado.');
    }

    public static function database(?string $name = null): Database
    {
        $client = self::client();
        $selected = $name ?? self::databaseName();
        if ($selected === '') {
            throw new RuntimeException('Nome do banco MongoDB não pode ser vazio.');
        }
        return $client->selectDatabase($selected);
    }

    public static function collection(string $name): Collection
    {
        if ($name === '') {
            throw new RuntimeException('Nome da coleção MongoDB não pode ser vazio.');
        }
        return self::database()->selectCollection($name);
    }

    /** Troca o banco padrão (usado por testes para isolamento). */
    public static function useDatabase(?string $name): void
    {
        if ($name === '') {
            throw new RuntimeException('Nome do banco MongoDB não pode ser vazio.');
        }
        self::$databaseOverride = $name;
    }

    public static function ping(): bool
    {
        try {
            self::client()->selectDatabase('admin')->command(['ping' => 1])->toArray();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$client = null;
        self::$databaseName = null;
        self::$databaseOverride = null;
    }

    private static function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            throw new RuntimeException($name . ' é obrigatório para usar a infraestrutura MongoDB.');
        }
        return trim($value);
    }
}
