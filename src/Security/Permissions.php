<?php
declare(strict_types=1);

namespace Saa\Security;

use InvalidArgumentException;
use Saa\Domain\Profile;

/**
 * Matriz de permissões por perfil (controle de acesso — issues #31 e #50).
 *
 * Toda verificação de acesso passa por Permissions::can(). As rotas declaram a permissão exigida
 * (opção 'permission') e as views escondem o que o perfil não pode usar (helper can()).
 * Um nome de permissão desconhecido lança exceção, para que erros de digitação não virem "acesso negado" silencioso.
 */
final class Permissions
{
    public const DASHBOARD_VIEW = 'dashboard.view';
    public const ABSENCES_REGISTER = 'absences.register';
    public const OCCURRENCES_VIEW = 'occurrences.view';
    public const COMMUNICATIONS_SEND = 'communications.send';
    public const REGISTRY_VIEW = 'registry.view';
    public const REGISTRY_EDIT = 'registry.edit';
    public const USERS_MANAGE = 'users.manage';
    public const JUSTIFICATION_SUBMIT = 'justification.submit';

    /** @return array<string, string> código da permissão => descrição em pt-BR */
    public static function all(): array
    {
        return [
            self::DASHBOARD_VIEW => 'Ver o painel de indicadores',
            self::ABSENCES_REGISTER => 'Registrar ausências',
            self::OCCURRENCES_VIEW => 'Consultar ocorrências e justificativas',
            self::COMMUNICATIONS_SEND => 'Enviar comunicações por e-mail',
            self::REGISTRY_VIEW => 'Consultar alunos, turmas e responsáveis',
            self::REGISTRY_EDIT => 'Cadastrar e alterar alunos, turmas e responsáveis',
            self::USERS_MANAGE => 'Cadastrar usuários e definir perfis',
            self::JUSTIFICATION_SUBMIT => 'Enviar justificativa pelo link recebido por e-mail',
        ];
    }

    /** @return array<string, list<string>> código do perfil => permissões concedidas */
    public static function grants(): array
    {
        $internal = [
            self::DASHBOARD_VIEW, self::ABSENCES_REGISTER, self::OCCURRENCES_VIEW,
            self::COMMUNICATIONS_SEND, self::REGISTRY_VIEW,
        ];
        return [
            Profile::ADMIN->value => array_values(array_diff(array_keys(self::all()), [self::JUSTIFICATION_SUBMIT])),
            Profile::COORDINATION->value => $internal,
            Profile::AOE->value => [...$internal, self::REGISTRY_EDIT],
            Profile::SECRETARY->value => [self::REGISTRY_VIEW, self::REGISTRY_EDIT],
            Profile::GUARDIAN->value => [self::JUSTIFICATION_SUBMIT],
        ];
    }

    public static function can(Profile|string|null $profile, string $permission): bool
    {
        if (!array_key_exists($permission, self::all())) {
            throw new InvalidArgumentException("Permissão desconhecida: {$permission}");
        }
        $code = $profile instanceof Profile ? $profile->value : $profile;
        if ($code === null) {
            return false;
        }
        return in_array($permission, self::grants()[$code] ?? [], true);
    }

    /** @return list<string> permissões do perfil (vazio se o perfil for desconhecido) */
    public static function forProfile(Profile|string|null $profile): array
    {
        $code = $profile instanceof Profile ? $profile->value : $profile;
        return $code === null ? [] : (self::grants()[$code] ?? []);
    }
}
