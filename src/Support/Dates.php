<?php
declare(strict_types=1);

namespace Saa\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use MongoDB\BSON\UTCDateTime;
use Saa\Core\Config;
use Saa\Database\Bson;

/**
 * Formatação de datas para a interface (pt-BR). O armazenamento é sempre em UTC; a exibição usa o fuso escolar
 * (APP_TIMEZONE). Datas de calendário (data da ausência, nascimento) ficam em meia-noite UTC e NÃO são
 * deslocadas de fuso — senão apareceriam como o dia anterior.
 */
final class Dates
{
    public const PLACEHOLDER = '—';

    /** Converte UTCDateTime, DateTimeInterface, "AAAA-MM-DD[ T...]" ou epoch (int) em DateTimeImmutable; null se não der. */
    public static function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof UTCDateTime) {
            return Bson::dateTime($value);
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value)) {
            return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'));
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T].+)?$/', $value) === 1) {
            try {
                return new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (Exception) {
                return null;
            }
        }
        return null;
    }

    /**
     * d/m/Y. Texto "AAAA-MM-DD" e instantes exatamente à meia-noite UTC são datas de calendário (sem mudança de fuso);
     * os demais instantes são convertidos para o fuso escolar. Valor vazio ou inválido: "—".
     */
    public static function formatDate(mixed $value): string
    {
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1) {
            return Bson::isDay($value) ? $parts[3] . '/' . $parts[2] . '/' . $parts[1] : self::PLACEHOLDER;
        }
        $date = self::toDateTime($value);
        if ($date === null) {
            return self::PLACEHOLDER;
        }
        $utc = $date->setTimezone(new DateTimeZone('UTC'));
        if ($utc->format('H:i:s.u') === '00:00:00.000000') {
            return $utc->format('d/m/Y');
        }
        return $date->setTimezone(new DateTimeZone(Config::timezone()))->format('d/m/Y');
    }

    /** d/m/Y H:i no fuso escolar. Valor vazio ou inválido: "—". */
    public static function formatDateTime(mixed $value): string
    {
        $date = self::toDateTime($value);
        return $date === null ? self::PLACEHOLDER : $date->setTimezone(new DateTimeZone(Config::timezone()))->format('d/m/Y H:i');
    }
}
