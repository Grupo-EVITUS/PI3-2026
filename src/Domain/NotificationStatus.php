<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Último estado de um e-mail de ausência. "Enviado" não significa "lido": OPENED só vem de evento do provedor. */
enum NotificationStatus: string
{
    use EnumHelpers;

    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case OPENED = 'OPENED';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Na fila',
            self::SENT => 'Enviado',
            self::DELIVERED => 'Entregue',
            self::OPENED => 'Aberto',
            self::FAILED => 'Falha',
        };
    }
}
