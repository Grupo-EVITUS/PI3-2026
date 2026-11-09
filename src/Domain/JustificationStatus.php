<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Status da ocorrência de ausência (issue #45): nasce Pendente e vira Respondida quando o responsável justifica. */
enum JustificationStatus: string
{
    use EnumHelpers;

    case PENDING = 'PENDING';
    case RESPONDED = 'RESPONDED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::RESPONDED => 'Respondida',
        };
    }
}
