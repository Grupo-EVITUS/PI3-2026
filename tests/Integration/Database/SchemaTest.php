<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Database;

use Closure;
use InvalidArgumentException;
use MongoDB\BSON\Document;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use RuntimeException;
use Saa\Core\Config;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Database\Schema;
use Saa\Tests\Support\MongoTestCase;
use Saa\Tests\Unit\Domain\EnumsTest;

/**
 * Modelo físico do MongoDB (issue #20): coleções, validators $jsonSchema, índices, unicidade e idempotência.
 * Roda contra um MongoDB real, em um banco temporário saa_test_<hex> criado por MongoTestCase.
 *
 * Rastreabilidade: cada teste leva #[Group('MD-NN')] (requisitos de docs/MODELAGEM_MONGODB.md);
 * rode um requisito com: phpunit --group MD-03
 */
final class SchemaTest extends MongoTestCase
{
    private const ID_STUDENT = '507f1f77bcf86cd799439011';
    private const ID_ENROLLMENT = '507f1f77bcf86cd799439021';
    private const ID_CLASS = '507f1f77bcf86cd799439031';
    private const ID_PERIOD = '507f1f77bcf86cd799439041';
    private const ID_ATTENDANCE = '507f1f77bcf86cd799439051';
    private const ID_GUARDIAN_1 = '507f1f77bcf86cd799439061';
    private const ID_GUARDIAN_2 = '507f1f77bcf86cd799439062';
    private const ID_GUARDIAN_3 = '507f1f77bcf86cd799439063';
    private const ID_GUARDIAN_4 = '507f1f77bcf86cd799439064';
    private const ID_NOTIFICATION = '507f1f77bcf86cd799439081';
    private const ID_USER = '507f1f77bcf86cd799439091';

    /** Marcado pelos testes que mudam validators/índices de propósito: o tearDown() restaura tudo. */
    private bool $schemaTouched = false;

    protected function tearDown(): void
    {
        if ($this->schemaTouched) {
            Schema::initialize($this->db());
            $this->schemaTouched = false;
        }
        parent::tearDown();
    }

    // ================================================================================== estrutura

    #[Group('MD-16')]
    public function testDefinitionsCoverTheTwelveCollections(): void
    {
        $definitions = Schema::definitions();

        self::assertSame(Collections::all(), array_keys($definitions));
        self::assertCount(12, $definitions);
        foreach ($definitions as $name => $definition) {
            self::assertSame(['validator', 'indexes'], array_keys($definition), $name);
            self::assertArrayHasKey('$jsonSchema', $definition['validator'], "{$name}: o validator deve usar \$jsonSchema");
            self::assertSame('object', $definition['validator']['$jsonSchema']['bsonType'], $name);
            self::assertNotEmpty($definition['validator']['$jsonSchema']['required'], "{$name}: campos obrigatórios");
            self::assertNotEmpty($definition['indexes'], "{$name}: índices");
        }
    }

    #[Group('MD-16')]
    public function testEveryCollectionHasAStrictJsonSchemaValidatorEqualToTheDefinition(): void
    {
        $stored = [];
        foreach ($this->db()->listCollections() as $info) {
            $stored[$info->getName()] = $info->getOptions();
        }

        foreach (Schema::definitions() as $name => $definition) {
            self::assertArrayHasKey($name, $stored, "coleção {$name} não foi criada");
            $options = $stored[$name];
            self::assertSame('strict', $options['validationLevel'] ?? null, "{$name}: validationLevel");
            self::assertSame('error', $options['validationAction'] ?? null, "{$name}: validationAction");
            self::assertSame(
                json_encode($definition['validator']),
                json_encode($options['validator'] ?? null),
                "{$name}: o validator gravado no MongoDB difere de Schema::definitions()"
            );
        }
    }

    #[Group('MD-10')]
    public function testEveryDeclaredIndexExistsWithItsOptionsAndNothingElse(): void
    {
        foreach (Schema::definitions() as $collection => $definition) {
            $actual = [];
            foreach ($this->db()->selectCollection($collection)->listIndexes() as $info) {
                $actual[$info->getName()] = $info;
            }

            $declaredNames = array_column($definition['indexes'], 'name');
            self::assertEqualsCanonicalizing(
                [...$declaredNames, '_id_'],
                array_keys($actual),
                "{$collection}: o conjunto de índices do banco deve ser exatamente o declarado"
            );
            foreach ($definition['indexes'] as $index) {
                $info = $actual[$index['name']];
                $where = "{$collection}.{$index['name']}";
                self::assertSame(array_keys($index['key']), array_keys($info->getKey()), "{$where}: campos/ordem da chave");
                self::assertEquals($index['key'], $info->getKey(), "{$where}: direções");
                self::assertSame((bool)($index['unique'] ?? false), (bool)($info['unique'] ?? false), "{$where}: unique");
                self::assertEquals($index['partialFilterExpression'] ?? null, $info['partialFilterExpression'] ?? null, "{$where}: parcial");
                self::assertEquals($index['expireAfterSeconds'] ?? null, $info['expireAfterSeconds'] ?? null, "{$where}: TTL");
            }
        }
    }

    #[Group('MD-18')]
    public function testIndexNamesAreExplicitAndFollowTheNamingConvention(): void
    {
        foreach (Schema::definitions() as $collection => $definition) {
            $names = array_column($definition['indexes'], 'name');
            self::assertSame($names, array_values(array_unique($names)), "{$collection}: nomes repetidos");
            foreach ($definition['indexes'] as $index) {
                self::assertIsString($index['name']);
                $prefix = isset($index['expireAfterSeconds']) ? 'ttl_' : (($index['unique'] ?? false) ? 'uq_' : 'ix_');
                self::assertStringStartsWith($prefix, $index['name'], "{$collection}: prefixo do índice {$index['name']}");
                self::assertLessThanOrEqual(100, strlen($index['name']));
            }
        }
    }

    #[Group('MD-17')]
    public function testTtlIndexesExpireTheSupportCollectionsAutomatically(): void
    {
        self::assertSame(
            ['ttl_password_resets_created_at' => ['created_at', 30 * 24 * 3600]],
            $this->ttlIndexes(Collections::PASSWORD_RESETS)
        );
        self::assertSame(
            ['ttl_login_attempts_at' => ['at', 3600]],
            $this->ttlIndexes(Collections::LOGIN_ATTEMPTS)
        );
        // coleções de domínio e de auditoria NUNCA expiram sozinhas
        foreach ([Collections::AUDIT_EVENTS, ...Collections::domain()] as $collection) {
            self::assertSame([], $this->ttlIndexes($collection), "{$collection} não pode ter TTL");
        }
    }

    // ================================================================================== inicialização idempotente

    #[Group('MD-18')]
    public function testInitializeIsIdempotentAndKeepsExistingData(): void
    {
        $this->insertOne(Collections::STUDENTS, self::valid(Collections::STUDENTS));

        Schema::initialize($this->db());
        Schema::initialize($this->db());
        $report = Schema::apply($this->db());

        self::assertCount(12, $report);
        foreach ($report as $row) {
            self::assertSame('updated', $row['action'], $row['collection']);
            self::assertSame([], $row['indexes_created'], $row['collection']);
            self::assertSame([], $row['indexes_rebuilt'], $row['collection']);
            self::assertSame([], $row['indexes_extra'], $row['collection']);
            self::assertSame($row['indexes_total'], count(Schema::definitions()[$row['collection']]['indexes']));
        }
        self::assertSame(1, $this->db()->selectCollection(Collections::STUDENTS)->countDocuments([]), 'initialize() nunca apaga dados');
        self::assertCount(12, iterator_to_array($this->db()->listCollectionNames()));
    }

    #[Group('MD-18')]
    public function testApplyCreatesEverythingOnAnEmptyDatabaseAndReportsIt(): void
    {
        $name = 'saa_test_' . bin2hex(random_bytes(6));
        $database = Mongo::client()->selectDatabase($name);
        try {
            $first = Schema::apply($database);
            $second = Schema::apply($database);

            self::assertSame(array_fill(0, 12, 'created'), array_column($first, 'action'));
            self::assertSame(array_fill(0, 12, 'updated'), array_column($second, 'action'));
            $created = array_sum(array_map(static fn (array $row): int => count($row['indexes_created']), $first));
            self::assertSame(33, $created, 'índices criados na primeira execução');
            self::assertSame(33, array_sum(array_column($first, 'indexes_total')));
        } finally {
            Mongo::client()->dropDatabase($name);
        }
    }

    #[Group('MD-18')]
    public function testInitializeRestoresAValidatorThatWasAltered(): void
    {
        $this->schemaTouched = true;
        $this->db()->modifyCollection(Collections::USERS, [
            'validator' => ['$jsonSchema' => ['bsonType' => 'object']],
            'validationLevel' => 'off',
            'validationAction' => 'warn',
        ]);
        $this->assertAccepted(Collections::USERS, ['name' => 'x'], 'validator desligado aceita qualquer coisa');
        $this->db()->selectCollection(Collections::USERS)->deleteMany([]);

        Schema::initialize($this->db());

        $options = [];
        foreach ($this->db()->listCollections(['filter' => ['name' => Collections::USERS]]) as $info) {
            $options = $info->getOptions();
        }
        self::assertSame('strict', $options['validationLevel']);
        self::assertSame('error', $options['validationAction']);
        $this->assertRejected(Collections::USERS, ['name' => 'x'], 'o validator voltou a valer');
    }

    #[Group('MD-18')]
    public function testInitializeRebuildsAnIndexWhoseOptionsDiffer(): void
    {
        $this->schemaTouched = true;
        $users = $this->db()->selectCollection(Collections::USERS);
        $users->dropIndex('uq_users_email_normalized');
        $users->createIndex(['email_normalized' => 1], ['name' => 'uq_users_email_normalized']); // sem unique

        $report = self::reportFor(Schema::apply($this->db()), Collections::USERS);

        self::assertSame(['uq_users_email_normalized'], $report['indexes_rebuilt']);
        self::assertSame([], $report['indexes_created'], 'índice recriado não conta como novo');
        $this->insertOne(Collections::USERS, self::valid(Collections::USERS));
        $this->assertDuplicate(Collections::USERS, self::valid(Collections::USERS), 'o índice voltou a ser único');
    }

    #[Group('MD-18')]
    public function testInitializeReplacesAnEquivalentIndexThatHasAnotherName(): void
    {
        $this->schemaTouched = true;
        $students = $this->db()->selectCollection(Collections::STUDENTS);
        $students->dropIndex('uq_students_student_code');
        $students->createIndex(['student_code' => 1], ['name' => 'student_code_antigo', 'unique' => true]);

        $report = self::reportFor(Schema::apply($this->db()), Collections::STUDENTS);

        self::assertSame(['uq_students_student_code'], $report['indexes_created']);
        self::assertSame([], $report['indexes_extra']);
        $names = array_map(static fn ($info): string => $info->getName(), iterator_to_array($students->listIndexes(), false));
        self::assertContains('uq_students_student_code', $names);
        self::assertNotContains('student_code_antigo', $names);
    }

    #[Group('MD-18')]
    public function testInitializeNeverRemovesIndexesItDoesNotKnow(): void
    {
        $this->schemaTouched = true;
        $students = $this->db()->selectCollection(Collections::STUDENTS);
        $students->createIndex(['birth_date' => 1, 'name' => 1], ['name' => 'ix_manual_da_secretaria']);
        try {
            $report = self::reportFor(Schema::apply($this->db()), Collections::STUDENTS);

            self::assertSame(['ix_manual_da_secretaria'], $report['indexes_extra']);
            $names = array_map(static fn ($info): string => $info->getName(), iterator_to_array($students->listIndexes(), false));
            self::assertContains('ix_manual_da_secretaria', $names);
        } finally {
            $students->dropIndex('ix_manual_da_secretaria');
        }
    }

    #[Group('MD-18')]
    public function testInitializeExplainsWhenExistingDataBlocksAUniqueIndex(): void
    {
        $this->schemaTouched = true;
        $students = $this->db()->selectCollection(Collections::STUDENTS);
        $students->dropIndex('uq_students_student_code');
        try {
            $students->insertMany([self::valid(Collections::STUDENTS), self::valid(Collections::STUDENTS)]); // mesmo RA duas vezes

            try {
                Schema::apply($this->db());
                self::fail('deveria falhar: há RA duplicado e o índice único não pode ser criado');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('students', $e->getMessage());
                self::assertStringContainsString('uq_students_student_code', $e->getMessage());
                self::assertNotNull($e->getPrevious(), 'a causa original do MongoDB fica em getPrevious()');
            }
            self::assertSame(2, $students->countDocuments([]), 'nenhum dado é apagado nem corrigido sozinho');
        } finally {
            $students->deleteMany([]); // o tearDown() recria o índice
        }
    }

    // ================================================================================== bin/init_db.php

    #[Group('MD-18')]
    public function testInitDbScriptIsIdempotentAndKeepsData(): void
    {
        $this->insertOne(Collections::STUDENTS, self::valid(Collections::STUDENTS));

        [$code, $out, $err] = $this->runInitDb();
        [$codeAgain, $outAgain] = $this->runInitDb();

        self::assertSame(0, $code, $err);
        self::assertSame(0, $codeAgain);
        self::assertStringContainsString('Resumo: 12 coleções (0 criadas, 12 atualizadas); 33 índices (0 novos, 0 recriados).', $out);
        self::assertStringContainsString('Nenhum dado foi apagado.', $outAgain);
        self::assertStringContainsString(self::databaseName(), $out);
        self::assertSame(1, $this->db()->selectCollection(Collections::STUDENTS)->countDocuments([]));
    }

    #[Group('MD-18')]
    public function testInitDbScriptRefusesDropWithoutConfirmationAndInProduction(): void
    {
        $this->insertOne(Collections::STUDENTS, self::valid(Collections::STUDENTS));

        [$withoutYes, , $errNoYes] = $this->runInitDb(['--drop']);
        [$production, , $errProduction] = $this->runInitDb(['--drop', '--yes'], ['APP_ENV' => 'production']);
        [$unknown, , $errUnknown] = $this->runInitDb(['--limpar']);

        self::assertSame(2, $withoutYes);
        self::assertStringContainsString('--yes', $errNoYes);
        self::assertSame(3, $production);
        self::assertStringContainsString('APP_ENV=production', $errProduction);
        self::assertSame(2, $unknown);
        self::assertStringContainsString('Opção desconhecida', $errUnknown);
        self::assertSame(1, $this->db()->selectCollection(Collections::STUDENTS)->countDocuments([]), 'nenhuma recusa pode apagar dados');
    }

    #[Group('MD-18')]
    public function testInitDbScriptDropRecreatesEverythingWhenConfirmed(): void
    {
        $this->schemaTouched = true;
        $this->insertOne(Collections::STUDENTS, self::valid(Collections::STUDENTS));
        $this->insertOne(Collections::USERS, self::valid(Collections::USERS));

        [$code, $out, $err] = $this->runInitDb(['--drop', '--yes']);

        self::assertSame(0, $code, $err);
        self::assertStringContainsString('--drop: coleções removidas (12)', $out);
        self::assertStringContainsString('Resumo: 12 coleções (12 criadas, 0 atualizadas); 33 índices (33 novos, 0 recriados).', $out);
        self::assertStringContainsString('Banco recriado do zero.', $out);
        self::assertSame(0, $this->db()->selectCollection(Collections::STUDENTS)->countDocuments([]));
        self::assertSame(0, $this->db()->selectCollection(Collections::USERS)->countDocuments([]));
        $this->assertRejected(Collections::STUDENTS, ['name' => 'sem validator?'], 'o validator existe de novo');
    }

    #[Group('MD-18')]
    public function testInitDbScriptFailsWithNonZeroExitAndDoesNotLeakCredentials(): void
    {
        [$code, $out, $err] = $this->runInitDb([], [
            'MONGODB_URI' => 'mongodb://usuario:segredo-123@127.0.0.1:1/?serverSelectionTimeoutMS=300',
            'MONGODB_TIMEOUT_MS' => '500',
        ]);

        self::assertSame(1, $code);
        self::assertStringContainsString('não foi possível conectar', $err);
        self::assertStringNotContainsString('segredo-123', $out . $err, 'a senha da URI nunca é impressa');
        self::assertStringNotContainsString('usuario', $out . $err);
    }

    // ================================================================================== validators: aceitar e rejeitar

    /** @return array<string, array{string}> */
    public static function allCollections(): array
    {
        $cases = [];
        foreach (Collections::all() as $name) {
            $cases[$name] = [$name];
        }
        return $cases;
    }

    #[DataProvider('allCollections')]
    #[Group('MD-16')]
    public function testValidDocumentIsAccepted(string $collection): void
    {
        $this->assertAccepted($collection, self::valid($collection));
        self::assertSame(1, $this->db()->selectCollection($collection)->countDocuments([]));
    }

    /** @return array<string, array{string, string}> */
    public static function requiredFields(): array
    {
        $cases = [];
        foreach (Schema::definitions() as $collection => $definition) {
            foreach ($definition['validator']['$jsonSchema']['required'] as $field) {
                $cases["{$collection}: sem {$field}"] = [$collection, $field];
            }
        }
        return $cases;
    }

    #[DataProvider('requiredFields')]
    #[Group('MD-16')]
    #[Group('MD-08')]
    public function testRequiredFieldIsRejectedWhenMissing(string $collection, string $field): void
    {
        $document = self::valid($collection);
        self::assertArrayHasKey($field, $document, 'o documento válido de teste precisa ter todos os campos obrigatórios');
        unset($document[$field]);

        $this->assertRejected($collection, $document, "sem o campo obrigatório {$field}");
    }

    /** @return array<string, array{string, string, mixed}> */
    public static function wrongTypes(): array
    {
        $cases = [];
        foreach (Schema::definitions() as $collection => $definition) {
            foreach ($definition['validator']['$jsonSchema']['properties'] as $field => $property) {
                $cases["{$collection}.{$field}"] = [$collection, $field, self::wrongValueFor($property)];
            }
        }
        return $cases;
    }

    /** Um valor de tipo diferente de todos os aceitos pela propriedade (texto, número, data, id, booleano...). */
    private static function wrongValueFor(array $property): mixed
    {
        $type = $property['bsonType'] ?? null;
        $types = is_array($type) ? $type : [$type];
        return match (true) {
            $type === null => 'texto',
            in_array('string', $types, true) => 12345,
            in_array('objectId', $types, true) => 'abc',
            in_array('date', $types, true) => 'texto',
            in_array('bool', $types, true) => 'sim',
            in_array('number', $types, true) => '10',
            default => 'texto',
        };
    }

    #[DataProvider('wrongTypes')]
    #[Group('MD-16')]
    public function testWrongTypeIsRejected(string $collection, string $field, mixed $wrongValue): void
    {
        $document = self::valid($collection);
        $document[$field] = $wrongValue;

        $this->assertRejected($collection, $document, "{$field} com tipo errado");
    }

    /** @return array<string, array{string, Closure}> */
    public static function invalidDocuments(): array
    {
        $links = static fn (string ...$ids): array => array_map(
            static fn (string $id): array => [
                'guardian_id' => new ObjectId($id), 'relationship' => 'RESPONSAVEL',
                'is_primary' => false, 'receives_absence_notifications' => true,
            ],
            $ids
        );
        $token = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
        $historyItem = ['status' => 'PENDING', 'at' => self::at('2026-10-09T12:00:00Z'), 'by_user_id' => null, 'by_guardian_id' => null];
        $event = ['type' => 'SENT', 'occurred_at' => self::at('2026-10-09T12:01:00Z')];

        return [
            // ---- users
            'users: perfil GUARDIAN não tem conta' => [Collections::USERS, static fn (array $d): array => [...$d, 'profile' => 'GUARDIAN']],
            'users: perfil desconhecido' => [Collections::USERS, static fn (array $d): array => [...$d, 'profile' => 'DIRETOR']],
            'users: e-mail normalizado com maiúsculas' => [Collections::USERS, static fn (array $d): array => [...$d, 'email_normalized' => 'Mariana.Lima@escola.com']],
            'users: e-mail sem arroba' => [Collections::USERS, static fn (array $d): array => [...$d, 'email' => 'mariana.escola.com']],
            'users: e-mail com espaço' => [Collections::USERS, static fn (array $d): array => [...$d, 'email' => 'mariana lima@escola.com']],
            'users: nome com 2 caracteres' => [Collections::USERS, static fn (array $d): array => [...$d, 'name' => 'Ma']],
            'users: nome com 121 caracteres' => [Collections::USERS, static fn (array $d): array => [...$d, 'name' => str_repeat('a', 121)]],
            'users: senha em texto puro no password_hash' => [Collections::USERS, static fn (array $d): array => [...$d, 'password_hash' => 'SenhaForte123']],
            'users: password_hash com 256 caracteres' => [Collections::USERS, static fn (array $d): array => [...$d, 'password_hash' => '$2y$10$' . str_repeat('a', 249)]],
            'users: campo password em texto puro' => [Collections::USERS, static fn (array $d): array => [...$d, 'password' => 'SenhaForte123']],
            'users: campo senha em texto puro' => [Collections::USERS, static fn (array $d): array => [...$d, 'senha' => 'SenhaForte123']],
            'users: operador NoSQL no e-mail normalizado' => [Collections::USERS, static fn (array $d): array => [...$d, 'email_normalized' => ['$ne' => null]]],
            // ---- students
            'students: 4 responsáveis' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'guardians' => $links(self::ID_GUARDIAN_1, self::ID_GUARDIAN_2, self::ID_GUARDIAN_3, self::ID_GUARDIAN_4)]],
            'students: dois responsáveis principais' => [Collections::STUDENTS, static function (array $d): array {
                $d['guardians'][1]['is_primary'] = true;
                return $d;
            }],
            'students: responsável repetido' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'guardians' => $links(self::ID_GUARDIAN_1, self::ID_GUARDIAN_1)]],
            'students: parentesco inválido' => [Collections::STUDENTS, static function (array $d): array {
                $d['guardians'][0]['relationship'] = 'AVO';
                return $d;
            }],
            'students: vínculo sem receives_absence_notifications' => [Collections::STUDENTS, static function (array $d): array {
                unset($d['guardians'][0]['receives_absence_notifications']);
                return $d;
            }],
            'students: vínculo com guardian_id em texto' => [Collections::STUDENTS, static function (array $d): array {
                $d['guardians'][0]['guardian_id'] = self::ID_GUARDIAN_1;
                return $d;
            }],
            'students: nascimento com hora' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'birth_date' => self::at('2015-03-14T12:00:00Z')]],
            'students: RA vazio' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'student_code' => '']],
            'students: RA com 31 caracteres' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'student_code' => str_repeat('1', 31)]],
            'students: nome com 1 caractere' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'name' => 'A']],
            // ---- guardians
            'guardians: email_status inválido' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'email_status' => 'OK']],
            'guardians: e-mail normalizado com maiúsculas' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'email_normalized' => 'Carlos.Souza@exemplo.com']],
            'guardians: telefone numérico' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'phone' => 11999990000]],
            'guardians: telefone com 31 caracteres' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'phone' => str_repeat('9', 31)]],
            'guardians: nome com 1 caractere' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'name' => 'C']],
            // ---- academic_periods
            'academic_periods: fim antes do início' => [Collections::ACADEMIC_PERIODS, static fn (array $d): array => [...$d, 'end_date' => self::day('2026-01-01')]],
            'academic_periods: fim igual ao início' => [Collections::ACADEMIC_PERIODS, static fn (array $d): array => [...$d, 'end_date' => $d['start_date']]],
            'academic_periods: alerta abaixo do mínimo' => [Collections::ACADEMIC_PERIODS, static function (array $d): array {
                $d['attendance_policy'] = ['min_attendance_percent' => 75, 'alert_below_percent' => 70];
                return $d;
            }],
            'academic_periods: frequência mínima acima de 100' => [Collections::ACADEMIC_PERIODS, static function (array $d): array {
                $d['attendance_policy'] = ['min_attendance_percent' => 101, 'alert_below_percent' => 101];
                return $d;
            }],
            'academic_periods: frequência mínima negativa' => [Collections::ACADEMIC_PERIODS, static function (array $d): array {
                $d['attendance_policy'] = ['min_attendance_percent' => -1, 'alert_below_percent' => 80];
                return $d;
            }],
            'academic_periods: início com hora' => [Collections::ACADEMIC_PERIODS, static fn (array $d): array => [...$d, 'start_date' => self::at('2026-02-02T08:00:00Z')]],
            'academic_periods: política sem alerta' => [Collections::ACADEMIC_PERIODS, static function (array $d): array {
                unset($d['attendance_policy']['alert_below_percent']);
                return $d;
            }],
            'academic_periods: nome vazio' => [Collections::ACADEMIC_PERIODS, static fn (array $d): array => [...$d, 'name' => '']],
            // ---- classes
            'classes: turno inválido' => [Collections::CLASSES, static fn (array $d): array => [...$d, 'shift' => 'EVENING']],
            'classes: código vazio' => [Collections::CLASSES, static fn (array $d): array => [...$d, 'code' => '']],
            'classes: código com 21 caracteres' => [Collections::CLASSES, static fn (array $d): array => [...$d, 'code' => str_repeat('A', 21)]],
            'classes: período em texto' => [Collections::CLASSES, static fn (array $d): array => [...$d, 'academic_period_id' => self::ID_PERIOD]],
            // ---- enrollments
            'enrollments: vigente com data de fim' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'end_date' => self::day('2026-06-30')]],
            'enrollments: vigente com motivo de encerramento' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'end_reason' => 'TRANSFER']],
            'enrollments: encerrada sem motivo' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'active' => false, 'end_date' => self::day('2026-06-30')]],
            'enrollments: encerrada sem data de fim' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'active' => false, 'end_reason' => 'TRANSFER']],
            'enrollments: motivo de encerramento inválido' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'active' => false, 'end_date' => self::day('2026-06-30'), 'end_reason' => 'DESISTENCIA']],
            'enrollments: fim antes do início' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'active' => false, 'end_date' => self::day('2026-01-15'), 'end_reason' => 'TRANSFER']],
            'enrollments: início com hora' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'start_date' => self::at('2026-02-02T09:00:00Z')]],
            // ---- attendance_records
            'attendance_records: status inválido' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'status' => 'LATE']],
            'attendance_records: data com hora' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'date' => self::at('2026-10-09T08:30:00Z')]],
            'attendance_records: presença com justificativa' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'status' => 'PRESENT']],
            'attendance_records: justificativa PENDING já com motivo' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification']['reason_code'] = 'MEDICAL';
                return $d;
            }],
            'attendance_records: justificativa RESPONDED sem responsável' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification'] = [...self::responded('MEDICAL'), 'guardian_id' => null];
                return $d;
            }],
            'attendance_records: justificativa RESPONDED sem data de resposta' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification'] = [...self::responded('MEDICAL'), 'submitted_at' => null];
                return $d;
            }],
            'attendance_records: justificativa RESPONDED com motivo inválido' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification'] = self::responded('PREGUICA', null);
                return $d;
            }],
            'attendance_records: motivo OTHER sem explicação' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification'] = self::responded('OTHER', null);
                return $d;
            }],
            'attendance_records: motivo OTHER com explicação de 2 caracteres' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification'] = self::responded('OTHER', 'ok');
                return $d;
            }],
            'attendance_records: justificativa com status inválido' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification']['status'] = 'DONE';
                return $d;
            }],
            'attendance_records: justificativa sem status_history' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                unset($d['justification']['status_history']);
                return $d;
            }],
            'attendance_records: status_history vazio' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['justification']['status_history'] = [];
                return $d;
            }],
            'attendance_records: status_history com 11 itens' => [Collections::ATTENDANCE_RECORDS, static function (array $d) use ($historyItem): array {
                $d['justification']['status_history'] = array_fill(0, 11, $historyItem);
                return $d;
            }],
            'attendance_records: item do histórico sem data' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                unset($d['justification']['status_history'][0]['at']);
                return $d;
            }],
            'attendance_records: class_name vazio' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'class_name' => '']],
            'attendance_records: details sem source' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'details' => ['note' => 'sem origem']]],
            'attendance_records: observação com 501 caracteres' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'details' => ['source' => 'INTERNAL_MESSAGE', 'note' => str_repeat('n', 501)]]],
            // ---- email_notifications
            'email_notifications: status inválido' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'status' => 'READ']],
            'email_notifications: 4 tentativas' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'attempts' => 4]],
            'email_notifications: tentativas negativas' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'attempts' => -1]],
            'email_notifications: tentativas fracionárias' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'attempts' => 1.5]],
            'email_notifications: token_hash curto' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d): array {
                $d['justification_link']['token_hash'] = 'abc123';
                return $d;
            }],
            'email_notifications: token_hash em maiúsculas' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d) use ($token): array {
                $d['justification_link']['token_hash'] = strtoupper($token);
                return $d;
            }],
            'email_notifications: token em claro no lugar do hash' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d): array {
                $d['justification_link']['token_hash'] = 'Xk2pQ8vLm1Zr7TnB4sWc9YdFhJg3UaEo5IiNq6RtKwA';
                return $d;
            }],
            'email_notifications: link sem expires_at' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d): array {
                unset($d['justification_link']['expires_at']);
                return $d;
            }],
            'email_notifications: corpo com o link e o token em claro' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [
                ...$d,
                'body' => 'Justifique em https://saa.escola.com/justify/Xk2pQ8vLm1Zr7TnB4sWc9YdFhJg3UaEo5IiNq6RtKwA agora.',
            ]],
            'email_notifications: destinatário inválido' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'recipient_email' => 'carlos.souza']],
            'email_notifications: assunto vazio' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'subject' => '']],
            'email_notifications: corpo vazio' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'body' => '']],
            'email_notifications: evento de tipo inválido' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'events' => [['type' => 'READ', 'occurred_at' => self::at('2026-10-09T12:01:00Z')]]]],
            'email_notifications: evento sem data' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'events' => [['type' => 'SENT']]]],
            'email_notifications: 31 eventos' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [...$d, 'events' => array_fill(0, 31, $event)]],
            // ---- audit_events
            'audit_events: details em texto' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'details' => 'texto solto']],
            'audit_events: details como lista preenchida' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'details' => ['a', 'b']]],
            'audit_events: ação vazia' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'action' => '']],
            'audit_events: entity_id numérico' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'entity_id' => 12345]],
            'audit_events: IP com 65 caracteres' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'ip' => str_repeat('1', 65)]],
            // ---- password_resets
            'password_resets: token_hash inválido' => [Collections::PASSWORD_RESETS, static fn (array $d): array => [...$d, 'token_hash' => 'abc']],
            'password_resets: used_at em texto' => [Collections::PASSWORD_RESETS, static fn (array $d): array => [...$d, 'used_at' => '2026-10-09']],
            // ---- login_attempts
            'login_attempts: chave vazia' => [Collections::LOGIN_ATTEMPTS, static fn (array $d): array => [...$d, 'key' => '']],
            'login_attempts: chave com operador NoSQL' => [Collections::LOGIN_ATTEMPTS, static fn (array $d): array => [...$d, 'key' => ['$ne' => 1]]],
            // ---- message_templates
            'message_templates: variáveis repetidas' => [Collections::MESSAGE_TEMPLATES, static fn (array $d): array => [...$d, 'variables' => ['student_name', 'student_name']]],
            'message_templates: variáveis que não são texto' => [Collections::MESSAGE_TEMPLATES, static fn (array $d): array => [...$d, 'variables' => [1, 2]]],
            'message_templates: 31 variáveis' => [Collections::MESSAGE_TEMPLATES, static fn (array $d): array => [...$d, 'variables' => array_map(static fn (int $i): string => "var_{$i}", range(1, 31))]],
            'message_templates: assunto vazio' => [Collections::MESSAGE_TEMPLATES, static fn (array $d): array => [...$d, 'subject' => '']],
        ];
    }

    #[DataProvider('invalidDocuments')]
    #[Group('MD-16')]
    public function testInvalidDocumentIsRejected(string $collection, Closure $mutate): void
    {
        // controle positivo: o documento original é aceito; só a alteração o torna inválido
        $target = $this->db()->selectCollection($collection);
        $target->insertOne(self::valid($collection));
        $target->deleteMany([]);

        $this->assertRejected($collection, $mutate(self::valid($collection)));
    }

    /** @return array<string, array{string, Closure}> */
    public static function acceptedVariants(): array
    {
        $link = static fn (string $id, string $relationship, bool $primary, bool $notify): array => [
            'guardian_id' => new ObjectId($id), 'relationship' => $relationship,
            'is_primary' => $primary, 'receives_absence_notifications' => $notify,
        ];
        $hash = hash('sha256', 'outro-token');

        return [
            'users: com último login e criador' => [Collections::USERS, static fn (array $d): array => [
                ...$d, 'last_login_at' => self::at('2026-10-09T11:55:00Z'), 'created_by' => new ObjectId(self::ID_USER),
            ]],
            'users: hash argon2id' => [Collections::USERS, static fn (array $d): array => [
                ...$d, 'password_hash' => '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG',
            ]],
            'users: perfil SECRETARY' => [Collections::USERS, static fn (array $d): array => [...$d, 'profile' => 'SECRETARY']],
            'students: sem responsáveis' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'guardians' => []]],
            'students: três responsáveis, só um principal' => [Collections::STUDENTS, static fn (array $d): array => [
                ...$d, 'guardians' => [
                    $link(self::ID_GUARDIAN_1, 'PAI', true, true),
                    $link(self::ID_GUARDIAN_2, 'MAE', false, true),
                    $link(self::ID_GUARDIAN_3, 'RESPONSAVEL', false, false),
                ],
            ]],
            'students: nenhum responsável principal' => [Collections::STUDENTS, static fn (array $d): array => [
                ...$d, 'guardians' => [$link(self::ID_GUARDIAN_1, 'PAI', false, true)],
            ]],
            'guardians: telefone nulo' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'phone' => null]],
            'guardians: sem o campo telefone' => [Collections::GUARDIANS, static function (array $d): array {
                unset($d['phone']);
                return $d;
            }],
            'guardians: e-mail devolvido pelo provedor (BOUNCED)' => [Collections::GUARDIANS, static fn (array $d): array => [...$d, 'email_status' => 'BOUNCED']],
            'academic_periods: alerta igual ao mínimo' => [Collections::ACADEMIC_PERIODS, static function (array $d): array {
                $d['attendance_policy'] = ['min_attendance_percent' => 75, 'alert_below_percent' => 75];
                return $d;
            }],
            'academic_periods: percentuais decimais' => [Collections::ACADEMIC_PERIODS, static function (array $d): array {
                $d['attendance_policy'] = ['min_attendance_percent' => 72.5, 'alert_below_percent' => 80.25];
                return $d;
            }],
            'classes: turno integral' => [Collections::CLASSES, static fn (array $d): array => [...$d, 'shift' => 'FULL_TIME']],
            'enrollments: encerrada por troca de turma' => [Collections::ENROLLMENTS, static fn (array $d): array => [
                ...$d, 'active' => false, 'end_date' => self::day('2026-06-30'), 'end_reason' => 'CLASS_CHANGE',
            ]],
            'enrollments: encerrada no mesmo dia do início' => [Collections::ENROLLMENTS, static fn (array $d): array => [
                ...$d, 'active' => false, 'end_date' => $d['start_date'], 'end_reason' => 'TRANSFER',
            ]],
            'enrollments: vigente sem os campos de fim' => [Collections::ENROLLMENTS, static function (array $d): array {
                unset($d['end_date'], $d['end_reason']);
                return $d;
            }],
            'attendance_records: presença sem justificativa (null)' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'status' => 'PRESENT', 'justification' => null]],
            'attendance_records: presença sem o campo justification' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                $d['status'] = 'PRESENT';
                unset($d['justification'], $d['details']);
                return $d;
            }],
            'attendance_records: falta sem justificativa (null)' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'justification' => null]],
            'attendance_records: falta justificada e aceita (EXCUSED)' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [
                ...$d, 'status' => 'EXCUSED', 'justification' => self::responded('MEDICAL'),
            ]],
            'attendance_records: motivo OTHER com explicação' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [
                ...$d, 'justification' => self::responded('OTHER', 'Viagem de última hora para visitar a avó.'),
            ]],
            'attendance_records: resposta sem notificação (lançada pela equipe)' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [
                ...$d, 'justification' => [...self::responded('FAMILY', null), 'notification_id' => null],
            ]],
            'attendance_records: sem details' => [Collections::ATTENDANCE_RECORDS, static function (array $d): array {
                unset($d['details']);
                return $d;
            }],
            'attendance_records: observação nula' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'details' => ['source' => 'PHONE_CALL', 'note' => null]]],
            'email_notifications: na fila (PENDING, 0 tentativas, sem provedor)' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [
                ...$d, 'status' => 'PENDING', 'attempts' => 0, 'provider_message_id' => null, 'events' => [],
                'locked_at' => null, 'next_attempt_at' => self::at('2026-10-09T12:05:00Z'),
            ]],
            'email_notifications: três tentativas com falha' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [
                ...$d, 'status' => 'FAILED', 'attempts' => 3, 'provider_message_id' => null, 'last_error' => 'SMTP 550 caixa cheia',
                'events' => [['type' => 'FAILED', 'occurred_at' => self::at('2026-10-09T12:20:00Z'), 'detail' => 'SMTP 550 caixa cheia']],
            ]],
            'email_notifications: reenvio (resend_of)' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d) use ($hash): array {
                $d['resend_of'] = new ObjectId(self::ID_NOTIFICATION);
                $d['justification_link']['token_hash'] = $hash;
                return $d;
            }],
            'email_notifications: corpo com o link mascarado' => [Collections::EMAIL_NOTIFICATIONS, static fn (array $d): array => [
                ...$d, 'body' => 'Justifique em https://saa.escola.com/justify/{{token}} ou pelo link {{justification_link}}.',
            ]],
            'email_notifications: acesso ao link (LINK_ACCESSED)' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d): array {
                $d['events'][] = ['type' => 'LINK_ACCESSED', 'occurred_at' => self::at('2026-10-09T18:25:00Z')];
                return $d;
            }],
            'audit_events: details vazio (o array vazio do PHP)' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'details' => []]],
            'audit_events: entity_id em texto' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'entity_id' => 'login']],
            'audit_events: sem autor nem IP' => [Collections::AUDIT_EVENTS, static fn (array $d): array => [...$d, 'actor_user_id' => null, 'actor_label' => null, 'ip' => null]],
            'password_resets: já usado' => [Collections::PASSWORD_RESETS, static fn (array $d): array => [...$d, 'used_at' => self::at('2026-10-09T13:00:00Z')]],
            'message_templates: sem variáveis' => [Collections::MESSAGE_TEMPLATES, static fn (array $d): array => [...$d, 'variables' => []]],
        ];
    }

    #[DataProvider('acceptedVariants')]
    #[Group('MD-16')]
    public function testValidVariantIsAccepted(string $collection, Closure $mutate): void
    {
        $this->assertAccepted($collection, $mutate(self::valid($collection)));
    }

    // ================================================================================== regras de integridade

    #[Group('MD-01')]
    public function testStudentCodeIsRequiredAndUnique(): void
    {
        $withoutCode = self::valid(Collections::STUDENTS);
        unset($withoutCode['student_code']);
        $this->assertRejected(Collections::STUDENTS, $withoutCode, 'RA é obrigatório');

        $this->assertAccepted(Collections::STUDENTS, self::valid(Collections::STUDENTS));
        $this->assertDuplicate(Collections::STUDENTS, self::valid(Collections::STUDENTS), 'o mesmo RA em outro aluno');
        $this->assertAccepted(Collections::STUDENTS, [...self::valid(Collections::STUDENTS), 'student_code' => 'RA-2026-0002']);
    }

    #[Group('MD-04')]
    public function testStudentCanHaveAtMostThreeGuardiansWithOnePrimaryAndNoRepeats(): void
    {
        $guardianLink = static fn (string $id, bool $primary): array => [
            'guardian_id' => new ObjectId($id), 'relationship' => 'RESPONSAVEL',
            'is_primary' => $primary, 'receives_absence_notifications' => true,
        ];
        $student = static fn (array $links, string $code): array => [...self::valid(Collections::STUDENTS), 'student_code' => $code, 'guardians' => $links];

        $this->assertAccepted(Collections::STUDENTS, $student([], 'RA-0'));
        $this->assertAccepted(Collections::STUDENTS, $student([
            $guardianLink(self::ID_GUARDIAN_1, true), $guardianLink(self::ID_GUARDIAN_2, false), $guardianLink(self::ID_GUARDIAN_3, false),
        ], 'RA-3'));
        $this->assertRejected(Collections::STUDENTS, $student([
            $guardianLink(self::ID_GUARDIAN_1, true), $guardianLink(self::ID_GUARDIAN_2, false),
            $guardianLink(self::ID_GUARDIAN_3, false), $guardianLink(self::ID_GUARDIAN_4, false),
        ], 'RA-4'), 'o quarto responsável');
        $this->assertRejected(Collections::STUDENTS, $student([
            $guardianLink(self::ID_GUARDIAN_1, true), $guardianLink(self::ID_GUARDIAN_2, true),
        ], 'RA-P'), 'dois responsáveis principais');
        $this->assertRejected(Collections::STUDENTS, $student([
            $guardianLink(self::ID_GUARDIAN_1, true), $guardianLink(self::ID_GUARDIAN_1, false),
        ], 'RA-R'), 'o mesmo responsável duas vezes');
    }

    #[Group('MD-04')]
    public function testGuardianContactIsReferencedSoOneGuardianCanHaveSeveralChildren(): void
    {
        $guardian = self::valid(Collections::GUARDIANS);
        $guardian['_id'] = new ObjectId(self::ID_GUARDIAN_1);
        $this->assertAccepted(Collections::GUARDIANS, $guardian);

        // dois irmãos apontam para o MESMO responsável: o índice multikey de students.guardians não é único
        $this->assertAccepted(Collections::STUDENTS, [...self::valid(Collections::STUDENTS), 'student_code' => 'RA-IRMAO-1']);
        $this->assertAccepted(Collections::STUDENTS, [...self::valid(Collections::STUDENTS), 'student_code' => 'RA-IRMAO-2']);
        $children = $this->db()->selectCollection(Collections::STUDENTS)
            ->countDocuments(['guardians.guardian_id' => new ObjectId(self::ID_GUARDIAN_1)]);
        self::assertSame(2, $children);
    }

    /** @return array<string, array{string, Closure}> */
    public static function uniqueKeys(): array
    {
        return [
            'users.email_normalized' => [Collections::USERS, static fn (array $d): array => [
                ...$d, 'email' => 'outra@escola.com', 'email_normalized' => 'outra@escola.com',
            ]],
            'students.student_code' => [Collections::STUDENTS, static fn (array $d): array => [...$d, 'student_code' => 'RA-2026-0002']],
            'guardians.email_normalized' => [Collections::GUARDIANS, static fn (array $d): array => [
                ...$d, 'email' => 'outro@exemplo.com', 'email_normalized' => 'outro@exemplo.com',
            ]],
            'academic_periods.name' => [Collections::ACADEMIC_PERIODS, static fn (array $d): array => [...$d, 'name' => '2027', 'active' => false]],
            'classes.(academic_period_id, code)' => [Collections::CLASSES, static fn (array $d): array => [...$d, 'code' => '6B']],
            'enrollments.student_id (ativas)' => [Collections::ENROLLMENTS, static fn (array $d): array => [...$d, 'student_id' => new ObjectId(self::ID_GUARDIAN_4)]],
            'attendance_records.(student_id, date)' => [Collections::ATTENDANCE_RECORDS, static fn (array $d): array => [...$d, 'date' => self::day('2026-10-10')]],
            'email_notifications.justification_link.token_hash' => [Collections::EMAIL_NOTIFICATIONS, static function (array $d): array {
                $d['justification_link']['token_hash'] = hash('sha256', 'token-diferente');
                return $d;
            }],
            'password_resets.token_hash' => [Collections::PASSWORD_RESETS, static fn (array $d): array => [...$d, 'token_hash' => hash('sha256', 'token-diferente')]],
            'message_templates.code' => [Collections::MESSAGE_TEMPLATES, static fn (array $d): array => [...$d, 'code' => 'ABSENCE_REMINDER']],
        ];
    }

    #[DataProvider('uniqueKeys')]
    #[Group('MD-15')]
    public function testUniqueKeyRejectsDuplicatesButAcceptsDifferentValues(string $collection, Closure $different): void
    {
        $this->assertAccepted($collection, self::valid($collection));
        $this->assertDuplicate($collection, self::valid($collection), 'mesma chave natural');
        $this->assertAccepted($collection, $different(self::valid($collection)), 'chave diferente');
    }

    #[Group('MD-15')]
    public function testEmailsAreUniqueByNormalizedValueNotByHowTheyWereTyped(): void
    {
        $this->assertAccepted(Collections::USERS, self::valid(Collections::USERS));
        // outra grafia do mesmo e-mail: a aplicação normaliza para minúsculas e o índice barra a repetição
        $this->assertDuplicate(Collections::USERS, [...self::valid(Collections::USERS), 'email' => 'MARIANA.LIMA@ESCOLA.COM'], 'users');

        $this->assertAccepted(Collections::GUARDIANS, self::valid(Collections::GUARDIANS));
        $this->assertDuplicate(Collections::GUARDIANS, [...self::valid(Collections::GUARDIANS), 'email' => 'CARLOS.SOUZA@EXEMPLO.COM'], 'guardians');
        // e a forma normalizada com maiúsculas nem chega a ser gravada
        $this->assertRejected(Collections::GUARDIANS, [
            ...self::valid(Collections::GUARDIANS), 'email_normalized' => 'CARLOS.SOUZA@EXEMPLO.COM',
        ], 'email_normalized só em minúsculas');
    }

    #[Group('MD-15')]
    public function testClassCodeIsUniqueOnlyInsideTheSameAcademicPeriod(): void
    {
        $this->assertAccepted(Collections::CLASSES, self::valid(Collections::CLASSES));
        $this->assertDuplicate(Collections::CLASSES, self::valid(Collections::CLASSES), 'mesmo código no mesmo período');
        $this->assertAccepted(Collections::CLASSES, [
            ...self::valid(Collections::CLASSES), 'academic_period_id' => new ObjectId(self::ID_GUARDIAN_4),
        ], 'o código 6A pode existir em outro período letivo');
    }

    #[Group('MD-13')]
    #[Group('MD-02')]
    public function testOnlyOneActiveEnrollmentPerStudentButManyEndedOnes(): void
    {
        $ended = static fn (string $start, string $end, string $reason): array => [
            ...self::valid(Collections::ENROLLMENTS),
            'start_date' => self::day($start), 'end_date' => self::day($end), 'end_reason' => $reason, 'active' => false,
        ];

        // histórico: várias matrículas encerradas do mesmo aluno
        $this->assertAccepted(Collections::ENROLLMENTS, $ended('2026-02-02', '2026-03-01', 'CLASS_CHANGE'));
        $this->assertAccepted(Collections::ENROLLMENTS, $ended('2026-03-02', '2026-05-01', 'CLASS_CHANGE'));
        $this->assertAccepted(Collections::ENROLLMENTS, $ended('2026-05-02', '2026-06-01', 'TRANSFER'));
        // mais a vigente
        $this->assertAccepted(Collections::ENROLLMENTS, [...self::valid(Collections::ENROLLMENTS), 'start_date' => self::day('2026-06-02')]);
        // uma segunda vigente para o mesmo aluno é impossível
        $this->assertDuplicate(Collections::ENROLLMENTS, [
            ...self::valid(Collections::ENROLLMENTS), 'class_id' => new ObjectId(self::ID_GUARDIAN_4),
        ], 'segunda matrícula ativa do mesmo aluno');
        // outro aluno pode ter a sua vigente
        $this->assertAccepted(Collections::ENROLLMENTS, [...self::valid(Collections::ENROLLMENTS), 'student_id' => new ObjectId(self::ID_GUARDIAN_4)]);

        $enrollments = $this->db()->selectCollection(Collections::ENROLLMENTS);
        self::assertSame(3, $enrollments->countDocuments(['student_id' => new ObjectId(self::ID_STUDENT), 'active' => false]));
        self::assertSame(1, $enrollments->countDocuments(['student_id' => new ObjectId(self::ID_STUDENT), 'active' => true]));

        // encerrar a vigente libera uma nova (é assim que o aluno muda de turma)
        $enrollments->updateOne(
            ['student_id' => new ObjectId(self::ID_STUDENT), 'active' => true],
            ['$set' => ['active' => false, 'end_date' => self::day('2026-08-01'), 'end_reason' => 'CLASS_CHANGE']]
        );
        $this->assertAccepted(Collections::ENROLLMENTS, [...self::valid(Collections::ENROLLMENTS), 'start_date' => self::day('2026-08-02')]);
    }

    #[Group('MD-02')]
    public function testEnrollmentIsActiveXorEndedWithDateAndReason(): void
    {
        $active = self::valid(Collections::ENROLLMENTS);
        $this->assertRejected(Collections::ENROLLMENTS, [...$active, 'end_date' => self::day('2026-06-30')], 'vigente com data de fim');
        $this->assertRejected(Collections::ENROLLMENTS, [...$active, 'active' => false], 'encerrada sem data nem motivo');
        $this->assertRejected(Collections::ENROLLMENTS, [
            ...$active, 'active' => false, 'end_date' => self::day('2026-01-01'), 'end_reason' => 'TRANSFER',
        ], 'fim antes do início');
        $this->assertAccepted(Collections::ENROLLMENTS, [
            ...$active, 'active' => false, 'end_date' => self::day('2026-06-30'), 'end_reason' => 'PERIOD_END',
        ]);
    }

    #[Group('MD-14')]
    public function testOnlyOneAcademicPeriodCanBeActiveButManyInactiveOnes(): void
    {
        $period = static fn (string $name, bool $active): array => [...self::valid(Collections::ACADEMIC_PERIODS), 'name' => $name, 'active' => $active];

        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $period('2026', true));
        $this->assertDuplicate(Collections::ACADEMIC_PERIODS, $period('2027', true), 'um segundo período ativo');
        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $period('2025', false));
        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $period('2024', false));
        $this->assertDuplicate(Collections::ACADEMIC_PERIODS, $period('2024', false), 'nome repetido, mesmo inativo');

        // trocar o período vigente: primeiro desativa o antigo, depois ativa o novo
        $periods = $this->db()->selectCollection(Collections::ACADEMIC_PERIODS);
        $periods->updateOne(['name' => '2026'], ['$set' => ['active' => false]]);
        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $period('2027', true));
    }

    #[Group('MD-12')]
    public function testAcademicPeriodKeepsItsAttendancePolicyEmbeddedAndCoherent(): void
    {
        $period = self::valid(Collections::ACADEMIC_PERIODS);
        self::assertSame(['min_attendance_percent' => 75, 'alert_below_percent' => 80], $period['attendance_policy']);

        $policy = static fn (int|float $min, int|float $alert): array => [
            ...$period,
            'name' => "p-{$min}-{$alert}",
            'active' => false,
            'attendance_policy' => ['min_attendance_percent' => $min, 'alert_below_percent' => $alert],
        ];
        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $policy(0, 0));
        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $policy(75, 80));
        $this->assertAccepted(Collections::ACADEMIC_PERIODS, $policy(100, 100));
        $this->assertRejected(Collections::ACADEMIC_PERIODS, $policy(75, 70), 'alerta abaixo do mínimo');
        $this->assertRejected(Collections::ACADEMIC_PERIODS, $policy(75, 101), 'percentual acima de 100');
        $this->assertRejected(Collections::ACADEMIC_PERIODS, $policy(-5, 80), 'percentual negativo');
        $this->assertRejected(Collections::ACADEMIC_PERIODS, [...$period, 'name' => 'sem-politica', 'active' => false, 'attendance_policy' => null], 'política obrigatória');
    }

    #[Group('MD-03')]
    public function testAttendanceAllowsOneRecordPerStudentPerDay(): void
    {
        $record = static fn (string $student, string $ymd): array => [
            ...self::valid(Collections::ATTENDANCE_RECORDS), 'student_id' => new ObjectId($student), 'date' => self::day($ymd),
        ];

        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, $record(self::ID_STUDENT, '2026-10-09'));
        $this->assertDuplicate(Collections::ATTENDANCE_RECORDS, $record(self::ID_STUDENT, '2026-10-09'), 'mesmo aluno no mesmo dia');
        $this->assertDuplicate(
            Collections::ATTENDANCE_RECORDS,
            [...$record(self::ID_STUDENT, '2026-10-09'), 'status' => 'EXCUSED', 'class_name' => 'Outra turma'],
            'mudar o status ou a turma não abre exceção'
        );
        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, $record(self::ID_STUDENT, '2026-10-10'));
        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, $record(self::ID_GUARDIAN_4, '2026-10-09'));
        self::assertSame(3, $this->db()->selectCollection(Collections::ATTENDANCE_RECORDS)->countDocuments([]));
    }

    #[Group('MD-03')]
    public function testAttendanceDateMustBeAMidnightUtcCalendarDay(): void
    {
        $record = self::valid(Collections::ATTENDANCE_RECORDS);
        foreach (['2026-10-09T00:00:01Z', '2026-10-09T03:00:00Z', '2026-10-09T12:00:00Z', '2026-10-09T23:59:59Z'] as $instant) {
            $this->assertRejected(Collections::ATTENDANCE_RECORDS, [...$record, 'date' => self::at($instant)], "data em {$instant}");
        }
        // sem essa regra, 09/10 às 00:00 e 09/10 às 12:00 seriam "dias diferentes" para o índice único (aluno, dia)
        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, [...$record, 'date' => Bson::day('2026-10-09')]);
        $this->assertDuplicate(Collections::ATTENDANCE_RECORDS, $record, 'o mesmo dia, de novo');
    }

    #[Group('MD-06')]
    public function testJustificationMovesFromPendingToRespondedWithWhoWhyAndWhen(): void
    {
        $attendance = [...self::valid(Collections::ATTENDANCE_RECORDS), '_id' => new ObjectId(self::ID_ATTENDANCE)];
        self::assertSame('PENDING', $attendance['justification']['status']);
        $records = $this->db()->selectCollection(Collections::ATTENDANCE_RECORDS);

        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, $attendance);
        // a escrita atômica do primeiro responsável (filtro justification.status = PENDING) passa pelo validator
        $first = $records->updateOne(
            ['_id' => $attendance['_id'], 'justification.status' => 'PENDING'],
            [
                '$set' => [
                    'justification.status' => 'RESPONDED',
                    'justification.guardian_id' => new ObjectId(self::ID_GUARDIAN_1),
                    'justification.reason_code' => 'MEDICAL',
                    'justification.explanation' => 'Consulta médica no período da manhã.',
                    'justification.submitted_at' => self::at('2026-10-09T18:30:00Z'),
                    'justification.notification_id' => new ObjectId(self::ID_NOTIFICATION),
                ],
                '$push' => ['justification.status_history' => [
                    'status' => 'RESPONDED', 'at' => self::at('2026-10-09T18:30:00Z'),
                    'by_user_id' => null, 'by_guardian_id' => new ObjectId(self::ID_GUARDIAN_1),
                ]],
            ]
        );
        self::assertSame(1, $first->getModifiedCount());
        // o segundo responsável não encontra mais PENDING: nada é alterado
        $second = $records->updateOne(
            ['_id' => $attendance['_id'], 'justification.status' => 'PENDING'],
            ['$set' => ['justification.reason_code' => 'FAMILY']]
        );
        self::assertSame(0, $second->getMatchedCount());

        // um estado pela metade é recusado pelo banco
        try {
            $records->updateOne(['_id' => $attendance['_id']], ['$set' => ['justification.reason_code' => null]]);
            self::fail('RESPONDED sem motivo deveria ser recusado');
        } catch (BulkWriteException $e) {
            self::assertSame(121, self::writeErrorCode($e));
        }
        $stored = $records->findOne(['_id' => $attendance['_id']]);
        self::assertIsArray($stored);
        self::assertSame('RESPONDED', $stored['justification']['status']);
        self::assertSame('MEDICAL', $stored['justification']['reason_code']);
        self::assertCount(2, $stored['justification']['status_history']);
    }

    #[Group('MD-06')]
    public function testOtherReasonNeedsAWrittenExplanationAndPresenceHasNoJustification(): void
    {
        $other = static fn (?string $explanation): array => [
            ...self::valid(Collections::ATTENDANCE_RECORDS), 'justification' => self::responded('OTHER', $explanation),
        ];
        $this->assertRejected(Collections::ATTENDANCE_RECORDS, $other(null), 'OTHER sem explicação');
        $this->assertRejected(Collections::ATTENDANCE_RECORDS, $other(''), 'OTHER com explicação vazia');
        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, $other('Viagem de última hora.'));

        $presence = [...self::valid(Collections::ATTENDANCE_RECORDS), 'student_id' => new ObjectId(self::ID_GUARDIAN_4), 'status' => 'PRESENT'];
        $this->assertRejected(Collections::ATTENDANCE_RECORDS, $presence, 'presença com justificativa');
        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, [...$presence, 'justification' => null]);
    }

    #[Group('MD-07')]
    public function testJustificationLinkKeepsOnlyTheTokenHashAndTheBodyNeverHasTheRawToken(): void
    {
        $notification = self::valid(Collections::EMAIL_NOTIFICATIONS);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $notification['justification_link']['token_hash']);
        $this->assertAccepted(Collections::EMAIL_NOTIFICATIONS, $notification);

        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        self::assertSame(43, strlen($rawToken));
        $fresh = static function (string $token): array {
            $notification = self::valid(Collections::EMAIL_NOTIFICATIONS);
            $notification['justification_link']['token_hash'] = hash('sha256', $token);
            $notification['justification_link']['used_at'] = null; // link novo, ainda não usado
            return $notification;
        };
        // o token em claro não serve como "hash"
        $plain = $fresh($rawToken);
        $plain['justification_link']['token_hash'] = $rawToken;
        $this->assertRejected(Collections::EMAIL_NOTIFICATIONS, $plain, 'token em claro no lugar do hash');
        // e o corpo gravado não pode carregar o link com o token em claro
        $this->assertRejected(Collections::EMAIL_NOTIFICATIONS, [...$fresh($rawToken), 'body' => "Justifique em https://saa.escola.com/justify/{$rawToken}"], 'corpo com token');
        $this->assertAccepted(Collections::EMAIL_NOTIFICATIONS, [...$fresh($rawToken), 'body' => 'Justifique em https://saa.escola.com/justify/[link individual no e-mail]']);

        // uso único: used_at guarda quando foi usado
        $notifications = $this->db()->selectCollection(Collections::EMAIL_NOTIFICATIONS);
        $used = $notifications->updateOne(
            ['justification_link.token_hash' => hash('sha256', $rawToken), 'justification_link.used_at' => null],
            ['$set' => ['justification_link.used_at' => self::at('2026-10-09T18:30:00Z')]]
        );
        self::assertSame(1, $used->getModifiedCount());
        $again = $notifications->updateOne(
            ['justification_link.token_hash' => hash('sha256', $rawToken), 'justification_link.used_at' => null],
            ['$set' => ['justification_link.used_at' => self::at('2026-10-09T19:00:00Z')]]
        );
        self::assertSame(0, $again->getMatchedCount(), 'o segundo uso do mesmo link não encontra mais o documento');
    }

    #[Group('MD-09')]
    public function testNotificationAttemptsAreLimitedToThreeAndEventsAreBounded(): void
    {
        $notification = static fn (array $changes): array => [...self::valid(Collections::EMAIL_NOTIFICATIONS), ...$changes];
        $unique = static function (array $document, int $n): array {
            $document['justification_link']['token_hash'] = hash('sha256', "token-{$n}");
            return $document;
        };
        foreach ([0, 1, 2, 3] as $attempts) {
            $this->assertAccepted(Collections::EMAIL_NOTIFICATIONS, $unique($notification(['attempts' => $attempts]), $attempts));
        }
        $this->assertRejected(Collections::EMAIL_NOTIFICATIONS, $unique($notification(['attempts' => 4]), 4), 'a quarta tentativa');

        $events = static fn (int $count): array => array_map(
            static fn (int $i): array => ['type' => 'OPENED', 'occurred_at' => self::at('2026-10-09T13:20:00Z')],
            range(1, $count)
        );
        $this->assertAccepted(Collections::EMAIL_NOTIFICATIONS, $unique($notification(['events' => $events(30)]), 30));
        $this->assertRejected(Collections::EMAIL_NOTIFICATIONS, $unique($notification(['events' => $events(31)]), 31), 'o evento 31');
    }

    #[Group('MD-19')]
    public function testEveryEmbeddedArrayInTheSchemaHasAnUpperLimit(): void
    {
        $arrays = [];
        $walk = static function (mixed $node, string $path) use (&$walk, &$arrays): void {
            if (!is_array($node)) {
                return;
            }
            $types = (array)($node['bsonType'] ?? []);
            if (in_array('array', $types, true)) {
                $arrays[$path] = $node['maxItems'] ?? null;
            }
            foreach ($node as $key => $child) {
                $walk($child, $path === '' ? (string)$key : "{$path}.{$key}");
            }
        };
        foreach (Schema::definitions() as $collection => $definition) {
            $walk($definition['validator']['$jsonSchema'], $collection);
        }

        self::assertNotEmpty($arrays);
        foreach ($arrays as $path => $maxItems) {
            self::assertIsInt($maxItems, "{$path}: array sem maxItems (poderia crescer sem limite)");
            self::assertLessThanOrEqual(30, $maxItems, "{$path}: limite alto demais");
        }
        // os quatro arrays embutidos do modelo, com os limites documentados
        self::assertSame(3, $arrays['students.properties.guardians']);
        self::assertSame(30, $arrays['email_notifications.properties.events']);
        self::assertSame(10, $arrays['attendance_records.properties.justification.properties.status_history']);
        self::assertSame(30, $arrays['message_templates.properties.variables']);
    }

    #[Group('MD-22')]
    public function testPasswordIsNeverStoredInPlainText(): void
    {
        $hash = password_hash('SenhaForte123', PASSWORD_DEFAULT);
        self::assertStringStartsWith('$2y$', $hash);
        $this->assertAccepted(Collections::USERS, [...self::valid(Collections::USERS), 'password_hash' => $hash]);

        foreach (['SenhaForte123', 'senha', '', 'plaintext password'] as $plain) {
            $this->assertRejected(
                Collections::USERS,
                [...self::valid(Collections::USERS), 'email_normalized' => 'x@x.com', 'password_hash' => $plain],
                "password_hash = '{$plain}'"
            );
        }
        foreach (['password', 'senha', 'plain_password'] as $forbiddenField) {
            $this->assertRejected(
                Collections::USERS,
                [...self::valid(Collections::USERS), 'email_normalized' => 'y@y.com', $forbiddenField => 'SenhaForte123'],
                "campo {$forbiddenField}"
            );
        }
    }

    #[Group('MD-22')]
    public function testNoSqlOperatorObjectsCannotBeStoredInTextFields(): void
    {
        $injection = ['$ne' => null];
        foreach ([
            Collections::USERS => 'email_normalized',
            Collections::STUDENTS => 'student_code',
            Collections::GUARDIANS => 'email_normalized',
            Collections::CLASSES => 'code',
            Collections::PASSWORD_RESETS => 'token_hash',
            Collections::LOGIN_ATTEMPTS => 'key',
            Collections::MESSAGE_TEMPLATES => 'code',
        ] as $collection => $field) {
            $this->assertRejected($collection, [...self::valid($collection), $field => $injection], "{$collection}.{$field}");
            $this->assertRejected($collection, [...self::valid($collection), $field => ['$regex' => '.*']], "{$collection}.{$field} (regex)");
        }
    }

    #[Group('MD-11')]
    public function testArchivingIsAFlagNotADeletionAndHistoryStaysConsistent(): void
    {
        foreach ([Collections::USERS, Collections::STUDENTS, Collections::GUARDIANS, Collections::ACADEMIC_PERIODS, Collections::CLASSES] as $collection) {
            $schema = Schema::definitions()[$collection]['validator']['$jsonSchema'];
            self::assertContains('active', $schema['required'], "{$collection}: arquivar = active=false");
            self::assertSame('bool', $schema['properties']['active']['bsonType'], $collection);
        }

        // arquivar o aluno preserva o documento e as faltas dele
        $this->assertAccepted(Collections::STUDENTS, self::valid(Collections::STUDENTS));
        $this->assertAccepted(Collections::ATTENDANCE_RECORDS, self::valid(Collections::ATTENDANCE_RECORDS));
        $this->db()->selectCollection(Collections::STUDENTS)->updateOne(['student_code' => 'RA-2026-0001'], ['$set' => ['active' => false]]);

        self::assertSame(1, $this->db()->selectCollection(Collections::STUDENTS)->countDocuments(['active' => false]));
        self::assertSame(1, $this->db()->selectCollection(Collections::ATTENDANCE_RECORDS)->countDocuments(['student_id' => new ObjectId(self::ID_STUDENT)]));
    }

    #[Group('MD-09')]
    public function testAuditEventsAcceptSystemActionsAndAnyDetailObject(): void
    {
        $audit = self::valid(Collections::AUDIT_EVENTS);
        $this->assertAccepted(Collections::AUDIT_EVENTS, $audit);
        $this->assertAccepted(Collections::AUDIT_EVENTS, [...$audit, 'actor_user_id' => null, 'actor_label' => 'Sistema', 'entity_id' => null, 'details' => ['qualquer' => ['coisa' => 1]]]);
        $this->assertAccepted(Collections::AUDIT_EVENTS, [...$audit, 'action' => 'auth.login_failed', 'entity_type' => 'users', 'entity_id' => 'email-nao-cadastrado']);
        $this->assertRejected(Collections::AUDIT_EVENTS, [...$audit, 'details' => 'texto'], 'details precisa ser objeto');
    }

    // ================================================================================== documentação testável

    #[Group('MD-16')]
    public function testEveryJsonExampleInTheDocumentationIsAcceptedByItsValidator(): void
    {
        $markdown = (string)file_get_contents(Config::basePath('docs/MODELAGEM_MONGODB.md'));
        preg_match_all('/<!-- exemplo:([a-z_]+) -->\R+```json\R(.*?)\R```/s', $markdown, $matches, PREG_SET_ORDER);

        $seen = [];
        foreach ($matches as [, $collection, $json]) {
            self::assertContains($collection, Collections::all(), "exemplo para coleção desconhecida: {$collection}");
            $document = Document::fromJSON($json)->toPHP(Mongo::TYPE_MAP);
            self::assertIsArray($document);
            $this->assertAccepted($collection, $document, "exemplo da documentação para {$collection}");
            $this->db()->selectCollection($collection)->deleteMany([]);
            $seen[$collection] = true;
        }
        self::assertEqualsCanonicalizing(Collections::all(), array_keys($seen), 'a documentação precisa de um exemplo para cada coleção');
    }

    #[Group('MD-16')]
    public function testDocumentationRequirementsPointToExistingTestsThatCarryTheSameGroup(): void
    {
        $markdown = (string)file_get_contents(Config::basePath('docs/MODELAGEM_MONGODB.md'));
        preg_match_all('/^\|\s*(MD-\d{2})\s*\|(.*)$/m', $markdown, $rows, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(20, count($rows), 'tabela de requisitos MD-NN da documentação');

        $classes = [
            'SchemaTest' => self::class,
            'FrequentQueriesTest' => FrequentQueriesTest::class,
            'EnumsTest' => EnumsTest::class,
        ];
        $documented = [];
        foreach ($rows as [, $code, $rest]) {
            $documented[] = $code;
            preg_match_all('/`(SchemaTest|FrequentQueriesTest|EnumsTest)::(test\w+)`/', $rest, $refs, PREG_SET_ORDER);
            self::assertNotEmpty($refs, "{$code}: a coluna 'onde é verificada' não cita nenhum teste");
            foreach ($refs as [, $class, $method]) {
                self::assertTrue(method_exists($classes[$class], $method), "{$code}: {$class}::{$method} não existe");
                self::assertContains($code, self::groupsOf($classes[$class], $method), "{$class}::{$method} deveria ter #[Group('{$code}')]");
            }
        }
        self::assertSame($documented, array_values(array_unique($documented)), 'requisito repetido na tabela');

        // e nenhum teste aponta para um requisito que a documentação não tem
        foreach ($classes as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach (self::groupsOf($class, $method->getName()) as $group) {
                    if (str_starts_with($group, 'MD-')) {
                        self::assertContains($group, $documented, "{$class}::{$method->getName()} usa o grupo {$group}, ausente da tabela");
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private static function groupsOf(string $class, string $method): array
    {
        return array_map(
            static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->name(),
            (new ReflectionMethod($class, $method))->getAttributes(Group::class)
        );
    }

    // ================================================================================== documentos de teste

    /**
     * Um documento VÁLIDO por coleção (os mesmos exemplos de docs/MODELAGEM_MONGODB.md). Os identificadores são
     * fixos de propósito: dois documentos criados por esta função colidem nas chaves únicas, e cada teste muda só
     * o campo que quer variar.
     *
     * @return array<string, mixed>
     */
    public static function valid(string $collection): array
    {
        $created = self::at('2026-02-01T12:00:00Z');
        $updated = self::at('2026-10-09T12:00:00Z');
        $guardianLink = static fn (string $id, string $relationship, bool $primary, bool $notify): array => [
            'guardian_id' => new ObjectId($id), 'relationship' => $relationship,
            'is_primary' => $primary, 'receives_absence_notifications' => $notify,
        ];

        return match ($collection) {
            Collections::USERS => [
                'name' => 'Mariana Lima',
                'email' => 'Mariana.Lima@escola.com',
                'email_normalized' => 'mariana.lima@escola.com',
                'password_hash' => '$2y$10$' . str_repeat('a', 53),
                'profile' => 'AOE',
                'active' => true,
                'last_login_at' => null,
                'password_changed_at' => $created,
                'created_at' => $created,
                'updated_at' => $updated,
                'created_by' => null,
            ],
            Collections::STUDENTS => [
                'name' => 'Ana Beatriz Souza',
                'student_code' => 'RA-2026-0001',
                'birth_date' => self::day('2015-03-14'),
                'guardians' => [
                    $guardianLink(self::ID_GUARDIAN_1, 'PAI', true, true),
                    $guardianLink(self::ID_GUARDIAN_2, 'MAE', false, true),
                ],
                'active' => true,
                'created_at' => $created,
                'updated_at' => $updated,
            ],
            Collections::GUARDIANS => [
                'name' => 'Carlos Souza',
                'email' => 'Carlos.Souza@exemplo.com',
                'email_normalized' => 'carlos.souza@exemplo.com',
                'email_status' => 'VALID',
                'phone' => '(11) 99999-0001',
                'active' => true,
                'created_at' => $created,
                'updated_at' => $updated,
            ],
            Collections::ACADEMIC_PERIODS => [
                'name' => '2026',
                'start_date' => self::day('2026-02-02'),
                'end_date' => self::day('2026-12-18'),
                'attendance_policy' => ['min_attendance_percent' => 75, 'alert_below_percent' => 80],
                'active' => true,
                'created_at' => $created,
                'updated_at' => $created,
            ],
            Collections::CLASSES => [
                'academic_period_id' => new ObjectId(self::ID_PERIOD),
                'code' => '6A',
                'name' => '6º Ano A',
                'grade' => '6º ano',
                'shift' => 'MORNING',
                'active' => true,
                'created_at' => $created,
                'updated_at' => $created,
            ],
            Collections::ENROLLMENTS => [
                'student_id' => new ObjectId(self::ID_STUDENT),
                'class_id' => new ObjectId(self::ID_CLASS),
                'academic_period_id' => new ObjectId(self::ID_PERIOD),
                'start_date' => self::day('2026-02-02'),
                'end_date' => null,
                'end_reason' => null,
                'active' => true,
                'created_at' => $created,
            ],
            Collections::ATTENDANCE_RECORDS => [
                'enrollment_id' => new ObjectId(self::ID_ENROLLMENT),
                'student_id' => new ObjectId(self::ID_STUDENT),
                'class_id' => new ObjectId(self::ID_CLASS),
                'class_name' => '6º Ano A',
                'academic_period_id' => new ObjectId(self::ID_PERIOD),
                'date' => self::day('2026-10-09'),
                'status' => 'ABSENT',
                'registered_by' => new ObjectId(self::ID_USER),
                'details' => [
                    'source' => 'INTERNAL_MESSAGE',
                    'note' => 'Ausência informada pela professora no grupo interno.',
                ],
                'justification' => self::pending(),
                'created_at' => $updated,
            ],
            Collections::EMAIL_NOTIFICATIONS => [
                'attendance_id' => new ObjectId(self::ID_ATTENDANCE),
                'student_id' => new ObjectId(self::ID_STUDENT),
                'guardian_id' => new ObjectId(self::ID_GUARDIAN_1),
                'recipient_email' => 'carlos.souza@exemplo.com',
                'subject' => 'Comunicado de ausência escolar',
                'body' => 'Olá, Carlos. Ana Beatriz faltou em 09/10/2026. Justifique pelo link individual: {{justification_link}}',
                'status' => 'OPENED',
                'attempts' => 1,
                'provider_message_id' => 'provider-123456',
                'justification_link' => [
                    'token_hash' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
                    'expires_at' => self::at('2026-11-08T12:00:00Z'),
                    'used_at' => self::at('2026-10-09T18:30:00Z'),
                ],
                'events' => [
                    ['type' => 'SENT', 'occurred_at' => self::at('2026-10-09T12:01:00Z')],
                    ['type' => 'DELIVERED', 'occurred_at' => self::at('2026-10-09T12:01:04Z')],
                    ['type' => 'OPENED', 'occurred_at' => self::at('2026-10-09T13:20:00Z')],
                    ['type' => 'LINK_ACCESSED', 'occurred_at' => self::at('2026-10-09T18:25:00Z')],
                ],
                'resend_of' => null,
                'locked_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'created_by' => new ObjectId(self::ID_USER),
                'created_at' => $updated,
            ],
            Collections::AUDIT_EVENTS => [
                'at' => $updated,
                'actor_user_id' => new ObjectId(self::ID_USER),
                'actor_label' => 'Mariana Lima (AOE)',
                'action' => 'attendance.create',
                'entity_type' => 'attendance_records',
                'entity_id' => new ObjectId(self::ID_ATTENDANCE),
                'details' => ['student_id' => self::ID_STUDENT, 'date' => '2026-10-09', 'status' => 'ABSENT'],
                'ip' => '127.0.0.1',
            ],
            Collections::PASSWORD_RESETS => [
                'user_id' => new ObjectId(self::ID_USER),
                'token_hash' => '2c26b46b68ffc68ff99b453c1d30413413422d706483bfa0f98a5e886266e7ae',
                'expires_at' => self::at('2026-10-09T13:00:00Z'),
                'used_at' => null,
                'created_at' => $updated,
                'ip' => '127.0.0.1',
            ],
            Collections::LOGIN_ATTEMPTS => [
                'key' => hash('sha256', 'mariana.lima@escola.com|127.0.0.1'),
                'at' => $updated,
            ],
            Collections::MESSAGE_TEMPLATES => [
                'code' => 'ABSENCE_NOTICE',
                'subject' => 'Comunicado de ausência escolar',
                'body' => 'Olá, {{guardian_name}}. {{student_name}} faltou em {{absence_date}}. Justifique pelo link individual: {{justification_link}}',
                'variables' => ['guardian_name', 'student_name', 'absence_date', 'justification_link'],
                'active' => true,
                'updated_at' => $updated,
            ],
            default => throw new InvalidArgumentException("Coleção desconhecida: {$collection}"),
        };
    }

    /** Justificativa recém-criada junto com a falta (aguardando o responsável). @return array<string, mixed> */
    private static function pending(): array
    {
        return [
            'status' => 'PENDING',
            'guardian_id' => null,
            'reason_code' => null,
            'explanation' => null,
            'submitted_at' => null,
            'notification_id' => null,
            'status_history' => [[
                'status' => 'PENDING',
                'at' => self::at('2026-10-09T12:00:00Z'),
                'by_user_id' => new ObjectId(self::ID_USER),
                'by_guardian_id' => null,
            ]],
        ];
    }

    /** Justificativa respondida pelo primeiro responsável. @return array<string, mixed> */
    private static function responded(string $reason, ?string $explanation = 'Consulta médica no período da manhã.'): array
    {
        $justification = self::pending();
        $justification['status'] = 'RESPONDED';
        $justification['guardian_id'] = new ObjectId(self::ID_GUARDIAN_1);
        $justification['reason_code'] = $reason;
        $justification['explanation'] = $explanation;
        $justification['submitted_at'] = self::at('2026-10-09T18:30:00Z');
        $justification['notification_id'] = new ObjectId(self::ID_NOTIFICATION);
        $justification['status_history'][] = [
            'status' => 'RESPONDED',
            'at' => self::at('2026-10-09T18:30:00Z'),
            'by_user_id' => null,
            'by_guardian_id' => new ObjectId(self::ID_GUARDIAN_1),
        ];
        return $justification;
    }

    private static function day(string $ymd): UTCDateTime
    {
        return Bson::day($ymd);
    }

    private static function at(string $iso): UTCDateTime
    {
        return Bson::date($iso) ?? throw new InvalidArgumentException("Data inválida: {$iso}");
    }

    // ================================================================================== utilitários

    /** @return array<string, array{string|int|null, mixed}> nome do índice TTL => [campo, segundos] */
    private function ttlIndexes(string $collection): array
    {
        $found = [];
        foreach ($this->db()->selectCollection($collection)->listIndexes() as $info) {
            if ($info->isTtl()) {
                $found[$info->getName()] = [array_key_first($info->getKey()), $info['expireAfterSeconds']];
            }
        }
        return $found;
    }

    /** @param array<string, mixed> $document */
    private function insertOne(string $collection, array $document): void
    {
        $this->db()->selectCollection($collection)->insertOne($document);
    }

    /** @param array<string, mixed> $document */
    private function assertAccepted(string $collection, array $document, string $message = ''): void
    {
        try {
            $this->insertOne($collection, $document);
        } catch (BulkWriteException $e) {
            self::fail("Documento válido foi recusado em {$collection} (código " . self::writeErrorCode($e) . ") {$message}: " . $e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    /** @param array<string, mixed> $document */
    private function assertRejected(string $collection, array $document, string $message = ''): void
    {
        $this->assertWriteFails(121, $collection, $document, $message);
    }

    /** @param array<string, mixed> $document */
    private function assertDuplicate(string $collection, array $document, string $message = ''): void
    {
        $this->assertWriteFails(11000, $collection, $document, $message);
    }

    /** @param array<string, mixed> $document */
    private function assertWriteFails(int $expectedCode, string $collection, array $document, string $message): void
    {
        try {
            $this->insertOne($collection, $document);
        } catch (BulkWriteException $e) {
            self::assertSame(
                $expectedCode,
                self::writeErrorCode($e),
                "{$collection}: esperava o erro {$expectedCode} ({$message}), veio: " . $e->getMessage()
            );
            return;
        }
        self::fail("{$collection}: o documento deveria ser recusado com o erro {$expectedCode} ({$message}), mas foi aceito.");
    }

    private static function writeErrorCode(BulkWriteException $e): int
    {
        $errors = $e->getWriteResult()->getWriteErrors();
        return $errors === [] ? (int)$e->getCode() : (int)$errors[0]->getCode();
    }

    /**
     * @param list<array{collection: string}&array<string, mixed>> $report
     * @return array<string, mixed>
     */
    private static function reportFor(array $report, string $collection): array
    {
        foreach ($report as $row) {
            if ($row['collection'] === $collection) {
                return $row;
            }
        }
        self::fail("relatório sem a coleção {$collection}");
    }

    /**
     * Executa bin/init_db.php em outro processo, apontando para o banco temporário deste teste.
     *
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{0: int, 1: string, 2: string} código de saída, stdout, stderr
     */
    private function runInitDb(array $args = [], array $env = []): array
    {
        $command = [PHP_BINARY];
        $ini = php_ini_loaded_file();
        if (is_string($ini)) {
            array_push($command, '-c', $ini);
        }
        $command[] = Config::basePath('bin/init_db.php');
        array_push($command, ...$args);

        $environment = array_merge(getenv(), [
            'MONGODB_URI' => Config::string('MONGODB_URI', 'mongodb://127.0.0.1:27017'),
            'MONGODB_DB' => self::databaseName(),
            'APP_ENV' => 'test',
        ], $env);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, Config::basePath(), $environment);
        if (!is_resource($process)) {
            self::fail('não foi possível iniciar o PHP para executar bin/init_db.php');
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}
