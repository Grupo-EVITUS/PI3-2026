<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use Saa\Core\Config;
use Throwable;

/**
 * Ponto único de conexão com o MongoDB (usa ext-mongodb + biblioteca mongodb/mongodb).
 *
 * Os resultados vêm como arrays PHP (typeMap): documentos e subdocumentos são arrays; ObjectId e
 * UTCDateTime continuam sendo objetos BSON (converta com Saa\Database\Bson).
 * Atenção: um array PHP vazio é gravado como ARRAY BSON; para gravar um documento vazio use new \stdClass.
 *
 * Variáveis: MONGODB_URI (padrão mongodb://127.0.0.1:27017), MONGODB_DB (padrão saa),
 * MONGODB_TIMEOUT_MS (padrão 5000).
 */
final class Mongo
{
    /** @var array{root: string, document: string, array: string} */
    public const TYPE_MAP = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private static ?Client $client = null;
    private static ?string $databaseName = null;

    public static function client(): Client
    {
        if (self::$client === null) {
            $timeout = max(500, Config::int('MONGODB_TIMEOUT_MS', 5000));
            self::$client = new Client(
                Config::string('MONGODB_URI', 'mongodb://127.0.0.1:27017'),
                [
                    'appname' => 'saa-php',
                    'serverSelectionTimeoutMS' => $timeout,
                    'connectTimeoutMS' => $timeout,
                ],
                ['typeMap' => self::TYPE_MAP]
            );
        }
        return self::$client;
    }

    public static function databaseName(): string
    {
        return self::$databaseName ?? Config::string('MONGODB_DB', 'saa');
    }

    public static function database(?string $name = null): Database
    {
        return self::client()->selectDatabase($name ?? self::databaseName());
    }

    public static function collection(string $name): Collection
    {
        return self::database()->selectCollection($name);
    }

    /** Troca o banco padrão do processo (usado pelos testes para isolar cada execução). */
    public static function useDatabase(?string $name): void
    {
        self::$databaseName = $name;
    }

    /** true se o servidor respondeu ao comando ping (nunca lança exceção). */
    public static function ping(): bool
    {
        try {
            self::client()->selectDatabase('admin')->command(['ping' => 1])->toArray();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Descarta cliente e banco escolhido (testes / mudança de configuração). */
    public static function reset(): void
    {
        self::$client = null;
        self::$databaseName = null;
    }
}
