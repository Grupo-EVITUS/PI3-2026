<?php
declare(strict_types=1);

namespace Saa\Core;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Relógio da aplicação. Centraliza "agora" e "hoje" para que os testes possam fixar o tempo.
 * Datas de ausência são datas de calendário no fuso escolar (APP_TIMEZONE), gravadas em UTC à meia-noite.
 */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;

    public static function now(): DateTimeImmutable
    {
        $zone = new DateTimeZone(Config::timezone());
        return self::$fixed !== null ? self::$fixed->setTimezone($zone) : new DateTimeImmutable('now', $zone);
    }

    /** Data de hoje no fuso escolar, formato Y-m-d. */
    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    /** Fixa o relógio (apenas testes). Aceita DateTimeImmutable, string parseável ou null para liberar. */
    public static function fix(DateTimeImmutable|string|null $at): void
    {
        self::$fixed = is_string($at) ? new DateTimeImmutable($at, new DateTimeZone('UTC')) : $at;
    }
}
