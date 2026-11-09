<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Perfis de usuário (issues #31 e #50). GUARDIAN não tem conta: acessa só o link individual de justificativa. */
enum Profile: string
{
    use EnumHelpers;

    case ADMIN = 'ADMIN';
    case COORDINATION = 'COORDINATION';
    case AOE = 'AOE';
    case SECRETARY = 'SECRETARY';
    case GUARDIAN = 'GUARDIAN';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Administrador',
            self::COORDINATION => 'Coordenador',
            self::AOE => 'AOE',
            self::SECRETARY => 'Secretária',
            self::GUARDIAN => 'Responsável',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ADMIN => 'Acesso completo, inclusive cadastro de usuários e definição de perfis.',
            self::COORDINATION => 'Acompanha indicadores e ocorrências, registra ausências e envia comunicações. Consulta os cadastros sem alterá-los.',
            self::AOE => 'Agente de Organização Escolar: registra ausências, envia comunicações e mantém os cadastros.',
            self::SECRETARY => 'Mantém os cadastros de alunos, turmas e responsáveis.',
            self::GUARDIAN => 'Não acessa a área interna. Recebe o e-mail de ausência e justifica pelo link individual, sem senha.',
        };
    }

    /** Perfis com conta (login por e-mail e senha). */
    public function canLogin(): bool
    {
        return $this !== self::GUARDIAN;
    }

    /** @return list<string> códigos dos perfis que podem ter usuário (ADMIN, COORDINATION, AOE, SECRETARY) */
    public static function loginValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->canLogin())
        ));
    }
}
