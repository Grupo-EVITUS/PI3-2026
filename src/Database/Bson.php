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

/**
 * Conversões BSON explícitas. IDs inteiros legados permanecem inteiros; nenhuma conversão
 * implícita de inteiro/string para ObjectId ou de valor arbitrário para data é feita aqui.
 */
final class Bson
{
    /** Aceita somente ObjectId ou hexadecimal de 24 caracteres. */
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

    /** Retorna hexadecimal somente para ObjectId/hex24; valores legados não são convertidos. */
    public static function id(mixed $id): ?string
    {
        if ($id instanceof ObjectId) {
            return (string)$id;
        }
        if (is_string($id) && self::isObjectId($id)) {
            return strtolower($id);
        }
        return null;
    }

    /** Converte somente tipos de data explícitos; strings e inteiros são ambíguos e rejeitados. */
    public static function date(mixed $value): ?UTCDateTime
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof UTCDateTime) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return new UTCDateTime($value);
        }
        throw new InvalidArgumentException('Data deve ser DateTimeInterface ou UTCDateTime.');
    }

    public static function dateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof UTCDateTime) {
            return DateTimeImmutable::createFromMutable($value->toDateTime())
                ->setTimezone(new DateTimeZone('UTC'));
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        return null;
    }

    /** Valida data de calendário estrita AAAA-MM-DD. */
    public static function isDay(string $ymd): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d') === $ymd;
    }

    /** Converte uma data de calendário explicitamente validada para meia-noite UTC. */
    public static function day(string $ymd): UTCDateTime
    {
        if (!self::isDay($ymd)) {
            throw new InvalidArgumentException('Data inválida: use o formato AAAA-MM-DD.');
        }
        return new UTCDateTime(new DateTimeImmutable($ymd . 'T00:00:00Z'));
    }

    public static function ymd(mixed $value): ?string
    {
        return self::dateTime($value)?->format('Y-m-d');
    }

    /** BSON -> apresentação preservando inteiros e strings já existentes. */
    public static function toPlain(mixed $value): mixed
    {
        if ($value instanceof ObjectId) {
            return (string)$value;
        }
        if ($value instanceof UTCDateTime) {
            return $value->toDateTime()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
        }
        if (is_array($value)) {
            return array_map(self::toPlain(...), $value);
        }
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            return self::toPlain($value->getArrayCopy());
        }
        return $value;
    }

    /** Regex "contém" case-insensitive, com escape para evitar injeção de regex. */
    public static function contains(string $term): Regex
    {
        $term = mb_substr(trim($term), 0, 100);
        return new Regex(preg_quote($term, '/'), 'i');
    }
}
