<?php
declare(strict_types=1);

namespace Saa\Services;

use Saa\Core\Clock;
use Saa\Database\Bson;
use Saa\Models\AcademicPeriodModel;
use Saa\Models\HomeStatsModel;
use Saa\Security\Permissions;

/**
 * Dados reais da tela inicial (issue #28), conforme o perfil do usuário:
 *  - registry.view    → alunos ativos, responsáveis ativos e turmas ativas do período vigente;
 *  - occurrences.view → ausências registradas hoje e ocorrências aguardando justificativa (justification PENDING);
 *  - todos            → período letivo vigente (ou aviso de que não há um) e se o usuário pode cadastrá-lo.
 * Só consulta o que o perfil pode ver; cada número é uma contagem única no banco (HomeStatsModel).
 */
final class HomeService
{
    public function __construct(
        private readonly HomeStatsModel $stats,
        private readonly AcademicPeriodModel $periods,
    ) {
    }

    /**
     * @param array{profile?: string} $user usuário autenticado (Request::user())
     * @return array{
     *     period: array<string, mixed>|null,
     *     can_manage_periods: bool,
     *     registry: array{students: int, guardians: int, classes: int}|null,
     *     occurrences: array{absences_today: int, pending: int, today: string}|null
     * }
     */
    public function summary(array $user): array
    {
        $profile = $user['profile'] ?? null;
        $period = $this->periods->findActive();

        $registry = Permissions::can($profile, Permissions::REGISTRY_VIEW)
            ? $this->stats->registryCounts(Bson::objectId($period['_id'] ?? null))
            : null;

        $occurrences = null;
        if (Permissions::can($profile, Permissions::OCCURRENCES_VIEW)) {
            $today = Clock::today();
            $occurrences = $this->stats->occurrenceCounts(Bson::day($today)) + ['today' => $today];
        }

        return [
            'period' => $period === null ? null : AcademicPeriodService::present($period),
            'can_manage_periods' => Permissions::can($profile, Permissions::REGISTRY_EDIT),
            'registry' => $registry,
            'occurrences' => $occurrences,
        ];
    }
}
