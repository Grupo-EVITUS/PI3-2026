<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Core\Config;

final class ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::reset();
        putenv('SAA_TEST_VALUE');
        parent::tearDown();
    }

    public function testReturnsDefaultWhenMissing(): void
    {
        self::assertNull(Config::get('SAA_TEST_UNDEFINED'));
        self::assertSame('fallback', Config::string('SAA_TEST_UNDEFINED', 'fallback'));
        self::assertSame(7, Config::int('SAA_TEST_UNDEFINED', 7));
        self::assertTrue(Config::bool('SAA_TEST_UNDEFINED', true));
    }

    public function testReadsFromEnvironmentAndOverrideWins(): void
    {
        putenv('SAA_TEST_VALUE=from-env');
        self::assertSame('from-env', Config::get('SAA_TEST_VALUE'));

        Config::set('SAA_TEST_VALUE', 'override');
        self::assertSame('override', Config::get('SAA_TEST_VALUE'));

        Config::set('SAA_TEST_VALUE', null);
        self::assertNull(Config::get('SAA_TEST_VALUE'));
    }

    /** @return array<string, array{string, bool}> */
    public static function booleans(): array
    {
        return [
            'true' => ['true', true], 'TRUE' => ['TRUE', true], 'one' => ['1', true], 'yes' => ['yes', true],
            'false' => ['false', false], 'zero' => ['0', false], 'garbage' => ['talvez', false],
        ];
    }

    #[DataProvider('booleans')]
    public function testBoolParsing(string $raw, bool $expected): void
    {
        Config::set('SAA_TEST_BOOL', $raw);
        self::assertSame($expected, Config::bool('SAA_TEST_BOOL'));
    }

    public function testIntRejectsNonNumeric(): void
    {
        Config::set('SAA_TEST_INT', '42');
        self::assertSame(42, Config::int('SAA_TEST_INT', 1));
        Config::set('SAA_TEST_INT', '4x2');
        self::assertSame(1, Config::int('SAA_TEST_INT', 1));
    }

    public function testEnvironmentAndTimezoneAreSanitized(): void
    {
        Config::set('APP_ENV', 'staging-inexistente');
        self::assertSame('development', Config::env());
        Config::set('APP_ENV', 'production');
        self::assertTrue(Config::isProduction());

        Config::set('APP_TIMEZONE', 'Mars/Olympus');
        self::assertSame('America/Sao_Paulo', Config::timezone());
        Config::set('APP_TIMEZONE', 'UTC');
        self::assertSame('UTC', Config::timezone());
    }

    public function testBasePathPointsToProjectRoot(): void
    {
        self::assertFileExists(Config::basePath('composer.json'));
        self::assertSame(Config::basePath() . '/src', Config::basePath('src'));
    }
}
