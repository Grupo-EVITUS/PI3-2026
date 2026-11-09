<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Domain\Profile;
use Saa\Security\Permissions;

/** Matriz de permissões por perfil (issues #31 e #50). Qualquer mudança na matriz precisa mudar este teste. */
final class PermissionsTest extends TestCase
{
    /** @return array<string, array{Profile, list<string>}> perfil => permissões esperadas (exatamente estas) */
    public static function expectedGrants(): array
    {
        return [
            'Administrador' => [Profile::ADMIN, [
                Permissions::DASHBOARD_VIEW, Permissions::ABSENCES_REGISTER, Permissions::OCCURRENCES_VIEW,
                Permissions::COMMUNICATIONS_SEND, Permissions::REGISTRY_VIEW, Permissions::REGISTRY_EDIT,
                Permissions::USERS_MANAGE,
            ]],
            'Coordenador' => [Profile::COORDINATION, [
                Permissions::DASHBOARD_VIEW, Permissions::ABSENCES_REGISTER, Permissions::OCCURRENCES_VIEW,
                Permissions::COMMUNICATIONS_SEND, Permissions::REGISTRY_VIEW,
            ]],
            'AOE' => [Profile::AOE, [
                Permissions::DASHBOARD_VIEW, Permissions::ABSENCES_REGISTER, Permissions::OCCURRENCES_VIEW,
                Permissions::COMMUNICATIONS_SEND, Permissions::REGISTRY_VIEW, Permissions::REGISTRY_EDIT,
            ]],
            'Secretária' => [Profile::SECRETARY, [Permissions::REGISTRY_VIEW, Permissions::REGISTRY_EDIT]],
            'Responsável' => [Profile::GUARDIAN, [Permissions::JUSTIFICATION_SUBMIT]],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('expectedGrants')]
    public function testEachProfileHasExactlyTheDocumentedPermissions(Profile $profile, array $expected): void
    {
        self::assertEqualsCanonicalizing($expected, Permissions::forProfile($profile));
        foreach (array_keys(Permissions::all()) as $permission) {
            self::assertSame(
                in_array($permission, $expected, true),
                Permissions::can($profile, $permission),
                "{$profile->value} x {$permission}"
            );
            self::assertSame(Permissions::can($profile, $permission), Permissions::can($profile->value, $permission));
        }
    }

    public function testEveryProfileIsCoveredAndOnlyAdministratorsManageUsers(): void
    {
        self::assertEqualsCanonicalizing(Profile::values(), array_keys(Permissions::grants()));
        foreach (Profile::cases() as $profile) {
            self::assertSame($profile === Profile::ADMIN, Permissions::can($profile, Permissions::USERS_MANAGE));
        }
    }

    public function testOnlyTheGuardianSubmitsJustificationsAndHasNothingElse(): void
    {
        foreach (Profile::cases() as $profile) {
            self::assertSame($profile === Profile::GUARDIAN, Permissions::can($profile, Permissions::JUSTIFICATION_SUBMIT));
        }
    }

    public function testEveryPermissionHasAPortugueseDescriptionAndIsGrantedToSomeone(): void
    {
        $granted = array_merge(...array_values(Permissions::grants()));
        foreach (Permissions::all() as $code => $description) {
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z]+$/', $code);
            self::assertNotSame('', trim($description));
            self::assertContains($code, $granted, "{$code} não é concedida a nenhum perfil");
        }
    }

    public function testUnknownProfileOrMissingUserHasNoPermission(): void
    {
        self::assertFalse(Permissions::can(null, Permissions::REGISTRY_VIEW));
        self::assertFalse(Permissions::can('DIRETOR', Permissions::REGISTRY_VIEW));
        self::assertFalse(Permissions::can('admin', Permissions::USERS_MANAGE), 'códigos são sensíveis a maiúsculas');
        self::assertSame([], Permissions::forProfile('DIRETOR'));
        self::assertSame([], Permissions::forProfile(null));
    }

    public function testUnknownPermissionIsAProgrammingErrorNotASilentDenial(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Permissions::can(Profile::ADMIN, 'registry.delete');
    }
}
