<?php
declare(strict_types=1);

namespace Saa\Tests\Support;

use MongoDB\Database;
use PHPUnit\Framework\TestCase;
use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Database\Mongo;
use Saa\Database\Schema;

/**
 * Base dos testes de integração: cada CLASSE de teste recebe um banco exclusivo (saa_test_<hex>) com
 * coleções, validators e índices criados por Schema::initialize(); os dados são limpos a cada teste
 * (índices e validators permanecem) e o banco é removido no final.
 *
 * Se o MongoDB não responder, os testes são marcados como "skipped" — exceto quando SAA_REQUIRE_MONGO=1
 * (usado no CI), caso em que falham. URI: variável MONGODB_URI (padrão mongodb://127.0.0.1:27017).
 */
abstract class MongoTestCase extends TestCase
{
    private static ?string $databaseName = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Config::reset();
        Mongo::reset();
        if (!Mongo::ping()) {
            $message = 'MongoDB indisponível em ' . Config::string('MONGODB_URI', 'mongodb://127.0.0.1:27017')
                . ' — defina MONGODB_URI ou suba o MongoDB (docker compose up -d mongo).';
            if (getenv('SAA_REQUIRE_MONGO') === '1') {
                self::fail($message);
            }
            self::markTestSkipped($message);
        }
        self::$databaseName = 'saa_test_' . bin2hex(random_bytes(6));
        Mongo::useDatabase(self::$databaseName);
        Config::set('MONGODB_DB', self::$databaseName);
        Schema::initialize(Mongo::database());
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$databaseName !== null && str_starts_with(self::$databaseName, 'saa_test_')) {
            try {
                Mongo::client()->dropDatabase(self::$databaseName);
            } catch (\Throwable) {
                // melhor esforço: banco temporário órfão não afeta os demais testes
            }
        }
        self::$databaseName = null;
        Mongo::reset();
        Config::reset();
        Clock::fix(null);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Clock::fix(null);
        foreach (Mongo::database()->listCollectionNames() as $name) {
            if (!str_starts_with($name, 'system.')) {
                Mongo::collection($name)->deleteMany([]);
            }
        }
    }

    protected function db(): Database
    {
        return Mongo::database();
    }

    protected static function databaseName(): string
    {
        return self::$databaseName ?? '';
    }
}
