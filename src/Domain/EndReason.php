<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Motivo de encerramento de uma matrícula (a matrícula nunca é apagada: o histórico é preservado). */
enum EndReason: string
{
    use EnumHelpers;

    case CLASS_CHANGE = 'CLASS_CHANGE';
    case TRANSFER = 'TRANSFER';
    case PERIOD_END = 'PERIOD_END';

    public function label(): string
    {
        return match ($this) {
            self::CLASS_CHANGE => 'Mudança de turma',
            self::TRANSFER => 'Transferência',
            self::PERIOD_END => 'Fim do período letivo',
        };
    }
}
