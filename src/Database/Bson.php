<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use Saa\Core\Clock;

/**
 * Conversões entre tipos BSON e PHP. Regras do projeto:
 *  - IDs trafegam como string hexadecimal de 24 caracteres (URLs, JSON, formulários) e são
 *    validados com Bson::objectId() ANTES de qualquer consulta;
 *  - datas são gravadas como UTCDateTime; datas de calendário (data da ausência, nascimento) ficam em
 *    meia-noite UTC do dia (Bson::day), e são lidas de volta com Bson::ymd().
 */
final class Bson
{
    /** Converte ObjectId ou string hex(24) em ObjectId; devolve null se inválido (nunca lança). */
    public static function objectId(mixed $id): ?ObjectId
    {
        if ($id instanceof ObjectId) {
            return $id;
        }
        if (is_string($id) && preg_match('/^[a-f0-9]{24}$/i', $id) === 1) {
            return new ObjectId(strtolower($id));
        }
        return null;
    }

    public static function isObjectId(mixed $id): bool
    {
        return self::objectId($id) !== null;
    }

    /** ObjectId → string hex; null → ''. */
    public static function id(mixed $id): string
    {
        return $id === null ? '' : (string)$id;
    }

    /** Instante atual (respeita Clock::fix()). */
    public static function now(): UTCDateTime
    {
        return new UTCDateTime(Clock::now());
    }

    /** Converte DateTimeInterface / UTCDateTime / string ISO-8601 / epoch (segundos) em UTCDateTime. */
    public static function date(mixed $value): ?UTCDateTime
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof UTCDateTime) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }
        if (is_int($value)) {
            return new UTCDateTime($value * 1000);
        }
        if (is_string($value)) {
            return new UTCDateTime(new DateTimeImmutable($value, new DateTimeZone('UTC')));
        }
        throw new InvalidArgumentException('Valor de data não suportado.');
    }

    /** UTCDateTime → DateTimeImmutable em UTC (null permanece null). */
    public static function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof UTCDateTime) {
            return DateTimeImmutable::createFromMutable($value->toDateTime())->setTimezone(new DateTimeZone('UTC'));
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        return null;
    }

    /** true se $ymd é uma data de calendário válida no formato AAAA-MM-DD. */
    public static function isDay(string $ymd): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $ymd;
    }

    /** Data de calendário AAAA-MM-DD → meia-noite UTC desse dia. Lança InvalidArgumentException se inválida. */
    public static function day(string $ymd): UTCDateTime
    {
        if (!self::isDay($ymd)) {
            throw new InvalidArgumentException('Data inválida: use o formato AAAA-MM-DD.');
        }
        return new UTCDateTime(new DateTimeImmutable($ymd . 'T00:00:00Z'));
    }

    /** Data de calendário gravada por day() → AAAA-MM-DD (null permanece null). */
    public static function ymd(mixed $value): ?string
    {
        $date = self::dateTime($value);
        return $date?->format('Y-m-d');
    }

    /**
     * Prepara um valor vindo do Mongo para JSON/exibição: ObjectId → string, UTCDateTime → ISO-8601 (UTC),
     * e percorre arrays/subdocumentos recursivamente.
     */
    public static function toPlain(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->format('Y-m-d\TH:i:s\Z');
        }
        if (is_array($value)) {
            return array_map(self::toPlain(...), $value);
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return self::toPlain($value->getArrayCopy());
        }
        return $value;
    }

    /** Regex "contém" case-insensitive com o termo escapado (evita injeção de regex). Máx. 100 caracteres. */
    public static function contains(string $term): Regex
    {
        $term = mb_substr(trim($term), 0, 100);
        return new Regex(preg_quote($term, '/'), 'i');
    }
}
