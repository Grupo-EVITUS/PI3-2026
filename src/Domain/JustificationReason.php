<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Motivos oferecidos ao responsável. OTHER exige explicação por escrito (RF18/RF19). */
enum JustificationReason: string
{
    use EnumHelpers;

    case ILLNESS = 'ILLNESS';
    case MEDICAL = 'MEDICAL';
    case FAMILY = 'FAMILY';
    case TRANSPORT = 'TRANSPORT';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::ILLNESS => 'Doença',
            self::MEDICAL => 'Consulta médica',
            self::FAMILY => 'Problema familiar',
            self::TRANSPORT => 'Problema de transporte',
            self::OTHER => 'Outro',
        };
    }

    public function requiresExplanation(): bool
    {
        return $this === self::OTHER;
    }
}
