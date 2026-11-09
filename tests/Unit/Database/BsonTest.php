<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Database;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Core\Clock;
use Saa\Database\Bson;

final class BsonTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::fix(null);
        parent::tearDown();
    }

    public function testObjectIdAcceptsOnlyValidHex(): void
    {
        $hex = '507f1f77bcf86cd799439011';
        self::assertSame($hex, (string)Bson::objectId($hex));
        self::assertSame($hex, (string)Bson::objectId(strtoupper($hex)));
        $oid = new ObjectId($hex);
        self::assertSame($oid, Bson::objectId($oid));

        foreach (['', 'abc', 'zzzzzzzzzzzzzzzzzzzzzzzz', $hex . '0', ['$ne' => 1], 123, null] as $invalid) {
            self::assertNull(Bson::objectId($invalid), 'não deve aceitar: ' . json_encode($invalid));
        }
        self::assertTrue(Bson::isObjectId($hex));
        self::assertFalse(Bson::isObjectId('x'));
        self::assertSame('', Bson::id(null));
        self::assertSame($hex, Bson::id($oid));
    }

    /** @return array<string, array{string, bool}> */
    public static function days(): array
    {
        return [
            'valid' => ['2026-10-09', true], 'leap day' => ['2028-02-29', true],
            'not leap' => ['2026-02-29', false], 'month 13' => ['2026-13-01', false],
            'no zero pad' => ['2026-1-9', false], 'br format' => ['09/10/2026', false], 'empty' => ['', false],
        ];
    }

    #[DataProvider('days')]
    public function testDayValidation(string $ymd, bool $valid): void
    {
        self::assertSame($valid, Bson::isDay($ymd));
        if ($valid) {
            self::assertSame($ymd, Bson::ymd(Bson::day($ymd)));
            self::assertSame($ymd . 'T00:00:00Z', Bson::toPlain(Bson::day($ymd)));
        } else {
            $this->expectException(InvalidArgumentException::class);
            Bson::day($ymd);
        }
    }

    public function testNowFollowsFixedClock(): void
    {
        Clock::fix('2026-11-04T15:30:00Z');
        self::assertSame('2026-11-04T15:30:00Z', Bson::toPlain(Bson::now()));
    }

    public function testDateConversions(): void
    {
        self::assertNull(Bson::date(null));
        self::assertNull(Bson::date(''));
        $utc = new UTCDateTime(0);
        self::assertSame($utc, Bson::date($utc));
        self::assertSame('1970-01-01T00:00:00Z', Bson::toPlain(Bson::date(0)));
        self::assertSame('2026-01-02T03:04:05Z', Bson::toPlain(Bson::date('2026-01-02T03:04:05Z')));
        self::assertNull(Bson::dateTime(null));
        self::assertSame('2026-01-02', Bson::ymd(Bson::date('2026-01-02T23:59:59Z')));
    }

    public function testToPlainConvertsNestedStructures(): void
    {
        $plain = Bson::toPlain([
            '_id' => new ObjectId('507f1f77bcf86cd799439011'),
            'when' => new UTCDateTime(1_700_000_000_000),
            'guardians' => [['guardian_id' => new ObjectId('507f1f77bcf86cd799439012'), 'is_primary' => true]],
            'note' => null,
        ]);
        self::assertSame('507f1f77bcf86cd799439011', $plain['_id']);
        self::assertSame('2023-11-14T22:13:20Z', $plain['when']);
        self::assertSame('507f1f77bcf86cd799439012', $plain['guardians'][0]['guardian_id']);
        self::assertNull($plain['note']);
    }

    public function testContainsEscapesRegexMetacharacters(): void
    {
        $regex = Bson::contains('a.b*(c)');
        self::assertSame('a\.b\*\(c\)', $regex->getPattern());
        self::assertSame('i', $regex->getFlags());
        self::assertSame(100, mb_strlen(Bson::contains(str_repeat('x', 500))->getPattern()));
    }
}
