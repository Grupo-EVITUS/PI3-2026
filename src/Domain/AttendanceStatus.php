<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Situação do registro diário de frequência. Falta justificada (EXCUSED) continua contando como ausência. */
enum AttendanceStatus: string
{
    use EnumHelpers;

    case PRESENT = 'PRESENT';
    case ABSENT = 'ABSENT';
    case EXCUSED = 'EXCUSED';

    public function label(): string
    {
        return match ($this) {
            self::PRESENT => 'Presente',
            self::ABSENT => 'Ausente',
            self::EXCUSED => 'Falta justificada',
        };
    }

    /** ABSENT e EXCUSED são ausências para qualquer indicador. */
    public function isAbsence(): bool
    {
        return $this !== self::PRESENT;
    }

    /** @return list<string> */
    public static function absenceValues(): array
    {
        return [self::ABSENT->value, self::EXCUSED->value];
    }
}
