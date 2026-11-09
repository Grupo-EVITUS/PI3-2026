<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Situação do e-mail cadastrado do responsável. Só VALID recebe comunicados. */
enum EmailStatus: string
{
    use EnumHelpers;

    case VALID = 'VALID';
    case UNVERIFIED = 'UNVERIFIED';
    case BOUNCED = 'BOUNCED';

    public function label(): string
    {
        return match ($this) {
            self::VALID => 'Válido',
            self::UNVERIFIED => 'Não verificado',
            self::BOUNCED => 'Retornou com erro',
        };
    }
}
