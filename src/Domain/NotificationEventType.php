<?php
declare(strict_types=1);

namespace Saa\Domain;

/** Tipos de evento no histórico (events[]) de um e-mail. LINK_ACCESSED é registrado pela própria aplicação. */
enum NotificationEventType: string
{
    use EnumHelpers;

    case SENT = 'SENT';
    case DELIVERED = 'DELIVERED';
    case OPENED = 'OPENED';
    case FAILED = 'FAILED';
    case LINK_ACCESSED = 'LINK_ACCESSED';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Enviado ao provedor',
            self::DELIVERED => 'Entregue',
            self::OPENED => 'E-mail aberto',
            self::FAILED => 'Falha no envio',
            self::LINK_ACCESSED => 'Link acessado',
        };
    }
}
