<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Database;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Tests\Support\MongoTestCase;

final class MongoConnectionTest extends MongoTestCase
{
    public function testPingAndIsolatedTestDatabase(): void
    {
        self::assertTrue(Mongo::ping());
        self::assertStringStartsWith('saa_test_', Mongo::databaseName());
        self::assertSame(self::databaseName(), Mongo::databaseName());
    }

    public function testRoundTripReturnsArraysWithBsonScalars(): void
    {
        // Coleção de sondagem sem validator: aqui só interessa o typeMap (as coleções do SAA validam o formato).
        $probe = Mongo::collection('typemap_probe');
        $id = new ObjectId();
        $probe->insertOne([
            '_id' => $id,
            'name' => 'Ana Beatriz Souza',
            'guardians' => [['guardian_id' => new ObjectId(), 'is_primary' => true]],
            'created_at' => Bson::now(),
        ]);

        $found = $probe->findOne(['_id' => $id]);

        self::assertIsArray($found);
        self::assertIsArray($found['guardians']);
        self::assertIsArray($found['guardians'][0]);
        self::assertInstanceOf(ObjectId::class, $found['guardians'][0]['guardian_id']);
        self::assertInstanceOf(UTCDateTime::class, $found['created_at']);
        self::assertSame((string)$id, Bson::toPlain($found)['_id']);
    }

    public function testDataIsClearedBetweenTests(): void
    {
        self::assertSame(0, Mongo::collection(Collections::STUDENTS)->countDocuments([]));
    }
}
