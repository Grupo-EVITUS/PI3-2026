<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Turno da turma. MORNING/AFTERNOON/NIGHT vêm do modelo do grupo; FULL_TIME (Integral) é extensão do MVP em PHP. */
enum Shift: string
{
    use EnumHelpers;

    case MORNING = 'MORNING';
    case AFTERNOON = 'AFTERNOON';
    case NIGHT = 'NIGHT';
    case FULL_TIME = 'FULL_TIME';

    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Manhã',
            self::AFTERNOON => 'Tarde',
            self::NIGHT => 'Noite',
            self::FULL_TIME => 'Integral',
        };
    }
}
