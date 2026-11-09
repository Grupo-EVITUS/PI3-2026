<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Domain;

use BackedEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;
use Saa\Database\Collections;
use Saa\Database\Schema;
use Saa\Domain\AttendanceStatus;
use Saa\Domain\EmailStatus;
use Saa\Domain\EndReason;
use Saa\Domain\JustificationReason;
use Saa\Domain\JustificationStatus;
use Saa\Domain\NotificationEventType;
use Saa\Domain\NotificationStatus;
use Saa\Domain\Profile;
use Saa\Domain\Relationship;
use Saa\Domain\Shift;
use stdClass;

/**
 * Enums de domínio (Saa\Domain): os códigos gravados no MongoDB e os rótulos exibidos em pt-BR.
 * Não usa banco de dados.
 */
final class EnumsTest extends TestCase
{
    /** @return array<string, array{class-string<BackedEnum>}> */
    public static function enums(): array
    {
        $classes = [
            AttendanceStatus::class, EmailStatus::class, EndReason::class, JustificationReason::class,
            JustificationStatus::class, NotificationEventType::class, NotificationStatus::class,
            Profile::class, Relationship::class, Shift::class,
        ];
        $cases = [];
        foreach ($classes as $class) {
            $cases[substr(strrchr($class, '\\') ?: $class, 1)] = [$class];
        }
        return $cases;
    }

    /** @param class-string<BackedEnum> $enum */
    #[DataProvider('enums')]
    #[Group('MD-21')]
    public function testValuesAreUniqueUpperSnakeStrings(string $enum): void
    {
        self::assertTrue((new ReflectionEnum($enum))->isBacked());
        self::assertSame('string', (string)(new ReflectionEnum($enum))->getBackingType());

        $values = $enum::values();
        self::assertNotEmpty($values);
        self::assertSame($values, array_values(array_unique($values)), 'códigos repetidos em ' . $enum);
        self::assertSame($values, array_map(static fn (BackedEnum $case): string => (string)$case->value, $enum::cases()));
        foreach ($values as $value) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Z_]*$/', $value, "código fora do padrão UPPER_SNAKE em {$enum}");
        }
    }

    /** @param class-string<BackedEnum> $enum */
    #[DataProvider('enums')]
    #[Group('MD-21')]
    public function testEveryCaseHasANonEmptyDistinctLabel(string $enum): void
    {
        $labels = [];
        foreach ($enum::cases() as $case) {
            $label = $case->label();
            self::assertNotSame('', trim($label), "rótulo vazio em {$enum}::{$case->name}");
            self::assertSame(trim($label), $label, 'rótulo com espaços sobrando');
            $labels[] = $label;
        }
        self::assertSame($labels, array_values(array_unique($labels)), "rótulos repetidos em {$enum}");
        self::assertSame(array_combine($enum::values(), $labels), $enum::options());
    }

    /** @param class-string<BackedEnum> $enum */
    #[DataProvider('enums')]
    #[Group('MD-21')]
    #[Group('MD-22')]
    public function testTryFromValueIgnoresHostileInput(string $enum): void
    {
        foreach ($enum::cases() as $case) {
            self::assertSame($case, $enum::tryFromValue($case->value));
        }

        $first = $enum::values()[0];
        $hostile = [
            'null' => null,
            'array vazio' => [],
            'array com operador' => ['$ne' => $first],
            'array com o código' => [$first],
            'inteiro' => 123,
            'zero' => 0,
            'decimal' => 1.5,
            'verdadeiro' => true,
            'falso' => false,
            'objeto' => new stdClass(),
            'texto vazio' => '',
            'texto desconhecido' => 'INEXISTENTE',
            'minúsculas' => strtolower($first),
            'com espaço no fim' => $first . ' ',
            'com quebra de linha' => $first . "\n",
            'injeção de regex' => '.*',
        ];
        foreach ($hostile as $description => $input) {
            self::assertNull($enum::tryFromValue($input), "tryFromValue deveria devolver null para: {$description}");
        }
    }

    /** @param class-string<BackedEnum> $enum */
    #[DataProvider('enums')]
    #[Group('MD-21')]
    public function testLabelForKnownUnknownAndInvalidCodes(string $enum): void
    {
        $first = $enum::cases()[0];
        self::assertSame($first->label(), $enum::labelFor($first->value));
        self::assertSame('CODIGO_ANTIGO', $enum::labelFor('CODIGO_ANTIGO'), 'código desconhecido volta como veio');
        self::assertSame('42', $enum::labelFor(42));
        self::assertSame('—', $enum::labelFor(null));
        self::assertSame('—', $enum::labelFor(['x']));
    }

    #[Group('MD-21')]
    #[Group('MD-15')]
    public function testProfileLoginValuesNeverIncludeGuardian(): void
    {
        self::assertSame(['ADMIN', 'COORDINATION', 'AOE', 'SECRETARY'], Profile::loginValues());
        self::assertNotContains(Profile::GUARDIAN->value, Profile::loginValues());
        self::assertContains(Profile::GUARDIAN->value, Profile::values(), 'GUARDIAN continua existindo como perfil de permissões');

        self::assertFalse(Profile::GUARDIAN->canLogin(), 'o responsável não tem conta');
        foreach ([Profile::ADMIN, Profile::COORDINATION, Profile::AOE, Profile::SECRETARY] as $profile) {
            self::assertTrue($profile->canLogin(), $profile->value);
        }
    }

    #[Group('MD-05')]
    public function testExcusedAbsenceStillCountsAsAbsence(): void
    {
        self::assertSame(['ABSENT', 'EXCUSED'], AttendanceStatus::absenceValues());
        self::assertTrue(AttendanceStatus::ABSENT->isAbsence());
        self::assertTrue(AttendanceStatus::EXCUSED->isAbsence());
        self::assertFalse(AttendanceStatus::PRESENT->isAbsence());
    }

    #[Group('MD-06')]
    public function testOnlyOtherReasonRequiresAWrittenExplanation(): void
    {
        foreach (JustificationReason::cases() as $reason) {
            self::assertSame($reason === JustificationReason::OTHER, $reason->requiresExplanation(), $reason->value);
        }
    }

    #[Group('MD-21')]
    #[Group('MD-16')]
    public function testSchemaValidatorsUseTheDomainEnumValues(): void
    {
        $definitions = Schema::definitions();
        self::assertSame(Collections::all(), array_keys($definitions), 'uma definição por coleção, na ordem de Collections::all()');

        $schema = static fn (string $collection): array => $definitions[$collection]['validator']['$jsonSchema']['properties'];
        $enumOf = static fn (array $property): array => array_values(array_filter($property['enum'], static fn (mixed $v): bool => $v !== null));

        $attendance = $schema(Collections::ATTENDANCE_RECORDS);
        $justification = $attendance['justification']['properties'];
        $notification = $schema(Collections::EMAIL_NOTIFICATIONS);

        $expected = [
            'users.profile' => [$enumOf($schema(Collections::USERS)['profile']), Profile::loginValues()],
            'students.guardians.relationship' => [
                $enumOf($schema(Collections::STUDENTS)['guardians']['items']['properties']['relationship']),
                Relationship::values(),
            ],
            'guardians.email_status' => [$enumOf($schema(Collections::GUARDIANS)['email_status']), EmailStatus::values()],
            'classes.shift' => [$enumOf($schema(Collections::CLASSES)['shift']), Shift::values()],
            'enrollments.end_reason' => [$enumOf($schema(Collections::ENROLLMENTS)['end_reason']), EndReason::values()],
            'attendance_records.status' => [$enumOf($attendance['status']), AttendanceStatus::values()],
            'attendance_records.justification.status' => [$enumOf($justification['status']), JustificationStatus::values()],
            'attendance_records.justification.reason_code' => [$enumOf($justification['reason_code']), JustificationReason::values()],
            'attendance_records.justification.status_history.status' => [
                $enumOf($justification['status_history']['items']['properties']['status']),
                JustificationStatus::values(),
            ],
            'email_notifications.status' => [$enumOf($notification['status']), NotificationStatus::values()],
            'email_notifications.events.type' => [
                $enumOf($notification['events']['items']['properties']['type']),
                NotificationEventType::values(),
            ],
        ];
        foreach ($expected as $path => [$inSchema, $inDomain]) {
            self::assertSame($inDomain, $inSchema, "o validator de {$path} deve listar exatamente os valores do enum de domínio");
        }

        // GUARDIAN não tem conta: não pode ser perfil de usuário.
        self::assertNotContains('GUARDIAN', $enumOf($schema(Collections::USERS)['profile']));
        // campos opcionais com enum aceitam null; os obrigatórios não.
        self::assertContains(null, $schema(Collections::ENROLLMENTS)['end_reason']['enum']);
        self::assertNotContains(null, $attendance['status']['enum']);
    }
}
