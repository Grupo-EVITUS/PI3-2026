<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Parentesco do responsável (códigos do modelo do grupo: PAI, MAE, RESPONSAVEL). */
enum Relationship: string
{
    use EnumHelpers;

    case PAI = 'PAI';
    case MAE = 'MAE';
    case RESPONSAVEL = 'RESPONSAVEL';

    public function label(): string
    {
        return match ($this) {
            self::PAI => 'Pai',
            self::MAE => 'Mãe',
            self::RESPONSAVEL => 'Responsável',
        };
    }
}
