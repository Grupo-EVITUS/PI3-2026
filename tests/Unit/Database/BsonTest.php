<?php
declare(strict_types=1);

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Database\Bson;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$hex = '507f1f77bcf86cd799439011';
$assert(Bson::id(Bson::objectId($hex)) === $hex, 'hex válido deve virar ObjectId');
$assert(Bson::objectId(42) === null && Bson::id(42) === null, 'inteiro legado não deve virar ObjectId');
$assert(Bson::objectId('$ne:1') === null, 'operador não deve ser aceito como ID');
$assert(Bson::date(null) === null, 'null deve continuar null');
$assert(Bson::isDay('2028-02-29'), 'ano bissexto deve ser aceito');
$assert(!Bson::isDay('2026-02-29'), 'data impossível deve ser recusada');
$assert(Bson::ymd(Bson::day('2028-02-29')) === '2028-02-29', 'dia deve preservar calendário');

try {
    Bson::date('2026-11-13');
    throw new RuntimeException('string ambígua não deveria ser convertida em data');
} catch (\InvalidArgumentException) {
}
$plain = Bson::toPlain([
    'legacy_integer_id' => 42,
    'legacy_string_id' => '0042',
    'object_id' => new ObjectId($hex),
    'instant' => new UTCDateTime(0),
]);
$assert($plain['legacy_integer_id'] === 42, 'inteiro legado deve permanecer inteiro');
$assert($plain['legacy_string_id'] === '0042', 'string legada deve permanecer string');
$assert($plain['object_id'] === $hex, 'ObjectId deve ser serializado em hex');
$assert($plain['instant'] === '1970-01-01T00:00:00Z', 'UTCDateTime deve ser serializado em UTC');
$assert(Bson::contains('a.b*(c)')->getPattern() === 'a\\.b\\*\\(c\\)', 'regex deve escapar metacaracteres');

fwrite(STDOUT, "BsonTest: 12 assertions passed.\n");
