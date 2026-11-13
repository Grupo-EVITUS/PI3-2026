<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Seed;

use Saa\Core\Config;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Support\PasswordHasher;
use Saa\Tests\Support\MongoTestCase;

/** Dados fictícios de demonstração (#25): bin/seed.php executado de verdade, em outro processo, no banco de teste. */
final class SeedScriptTest extends MongoTestCase
{
    private const PASSWORD = 'SenhaDoSeed-2026!';

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string} código de saída, stdout, stderr
     */
    private function runSeed(array $args = [], array $env = []): array
    {
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini)) {
            array_push($command, '-c', $ini);
        }
        $command[] = Config::basePath('bin/seed.php');
        array_push($command, ...$args);
        $environment = array_merge(getenv(), [
            'MONGODB_URI' => Config::string('MONGODB_URI', 'mongodb://127.0.0.1:27017'),
            'MONGODB_DB' => self::databaseName(),
            'APP_ENV' => 'test',
            'SEED_PASSWORD' => self::PASSWORD,
        ], $env);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Config::basePath(), $environment);
        self::assertIsResource($process);
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach ([Collections::USERS, Collections::ACADEMIC_PERIODS, Collections::CLASSES, Collections::STUDENTS,
                  Collections::GUARDIANS, Collections::ENROLLMENTS, Collections::ATTENDANCE_RECORDS,
                  Collections::EMAIL_NOTIFICATIONS] as $collection) {
            $counts[$collection] = Mongo::collection($collection)->countDocuments();
        }
        return $counts;
    }

    public function testIssue25SeedCreatesACoherentDemoSchoolThatPassesTheValidators(): void
    {
        [$code, $stdout, $stderr] = $this->runSeed();

        self::assertSame(0, $code, $stdout . $stderr);
        $counts = $this->counts();
        self::assertSame(4, $counts[Collections::USERS]);
        self::assertSame(1, $counts[Collections::ACADEMIC_PERIODS]);
        self::assertSame(3, $counts[Collections::CLASSES]);
        self::assertGreaterThanOrEqual(15, $counts[Collections::STUDENTS]);
        self::assertGreaterThanOrEqual(8, $counts[Collections::GUARDIANS]);
        self::assertSame($counts[Collections::STUDENTS], $counts[Collections::ENROLLMENTS]);
        self::assertGreaterThan(0, $counts[Collections::EMAIL_NOTIFICATIONS]);

        $records = Mongo::collection(Collections::ATTENDANCE_RECORDS);
        self::assertGreaterThan(0, $records->countDocuments(['status' => 'PRESENT']));
        self::assertGreaterThan(0, $records->countDocuments(['justification.status' => 'PENDING']));
        self::assertGreaterThan(0, $records->countDocuments(['justification.status' => 'RESPONDED']));
        self::assertGreaterThan(1, count($records->distinct('date', ['status' => 'ABSENT'])), 'faltas em vários dias');
        self::assertSame(1, Mongo::collection(Collections::ACADEMIC_PERIODS)->countDocuments(['active' => true]));

        // um perfil de cada, todos com a senha pedida e nenhum dado real
        self::assertEqualsCanonicalizing(['ADMIN', 'COORDINATION', 'AOE', 'SECRETARY'], Mongo::collection(Collections::USERS)->distinct('profile'));
        $admin = Mongo::collection(Collections::USERS)->findOne(['profile' => 'ADMIN']);
        self::assertTrue(PasswordHasher::verify(self::PASSWORD, $admin['password_hash']));
        self::assertStringNotContainsString(self::PASSWORD, $stdout, 'a senha vinda do ambiente não é impressa');
        foreach (Mongo::collection(Collections::GUARDIANS)->find([], ['projection' => ['email_normalized' => 1]]) as $guardian) {
            self::assertStringEndsWith('.test', $guardian['email_normalized'], 'domínio reservado: nenhum e-mail real');
        }
        // token em claro nunca é gravado
        self::assertSame(0, Mongo::collection(Collections::EMAIL_NOTIFICATIONS)->countDocuments(['body' => ['$regex' => '/justify/[A-Za-z0-9_-]{20,}']]));
    }

    public function testIssue25RunningTheSeedTwiceDuplicatesNothing(): void
    {
        self::assertSame(0, $this->runSeed()[0]);
        $first = $this->counts();

        [$code, $stdout] = $this->runSeed();

        self::assertSame(0, $code, $stdout);
        self::assertSame($first, $this->counts());
    }

    public function testIssue25SeedRefusesProductionUnlessForcedAndConfirmed(): void
    {
        [$plain, , $plainErr] = $this->runSeed([], ['APP_ENV' => 'production']);
        [$forced] = $this->runSeed(['--force'], ['APP_ENV' => 'production']);

        self::assertSame(3, $plain);
        self::assertStringContainsString('production', $plainErr);
        self::assertSame(3, $forced, '--force sem --yes também é recusado');
        self::assertSame(0, Mongo::collection(Collections::USERS)->countDocuments(), 'nada foi gravado');
    }

    public function testIssue25ResetRemovesOnlyDemoDataAndRecreatesIt(): void
    {
        self::assertSame(0, $this->runSeed()[0]);
        $before = $this->counts();
        Mongo::collection(Collections::GUARDIANS)->insertOne([
            'name' => 'Responsável Real', 'email' => 'real@escola-verdadeira.example', 'email_normalized' => 'real@escola-verdadeira.example',
            'email_status' => 'VALID', 'phone' => '(11) 98888-7777', 'active' => true,
            'created_at' => \Saa\Database\Bson::now(), 'updated_at' => \Saa\Database\Bson::now(),
        ]);

        [$code, $stdout, $stderr] = $this->runSeed(['--reset']);

        self::assertSame(0, $code, $stdout . $stderr);
        self::assertSame(1, Mongo::collection(Collections::GUARDIANS)->countDocuments(['email_normalized' => 'real@escola-verdadeira.example']), 'cadastro que não é de demonstração permanece');
        $after = $this->counts();
        self::assertSame($before[Collections::STUDENTS], $after[Collections::STUDENTS]);
        self::assertSame($before[Collections::GUARDIANS] + 1, $after[Collections::GUARDIANS]);
    }

    public function testUnknownOptionIsAUsageError(): void
    {
        [$code, , $stderr] = $this->runSeed(['--apagar-tudo']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Opção desconhecida', $stderr);
    }
}
