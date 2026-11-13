<?php
declare(strict_types=1);

namespace Saa\Database;

use MongoDB\Database;
use Throwable;

/** Verificação somente leitura da prontidão do contrato MongoDB candidato. */
final class HealthCheck
{
    /** @return array{status: string, checks: array<string, array{status: string, missing?: list<string>}>} */
    public static function inspect(Database $database): array
    {
        try {
            $collectionInfo = [];
            foreach ($database->listCollections() as $info) {
                $collectionInfo[$info->getName()] = $info->getOptions();
            }
        } catch (Throwable) {
            return [
                'status' => 'not_ready',
                'checks' => [
                    'collections' => ['status' => 'failed'],
                    'validators' => ['status' => 'failed'],
                    'indexes' => ['status' => 'failed'],
                ],
            ];
        }

        $existingCollections = array_keys($collectionInfo);
        $missingCollections = array_values(array_diff(Collections::legacyImport(), $existingCollections));
        $invalidValidators = [];
        $missingIndexes = [];
        foreach (Schema::definitions() as $collectionName => $definition) {
            if (!in_array($collectionName, $existingCollections, true)) {
                continue;
            }

            $options = $collectionInfo[$collectionName];
            $expectedValidator = Schema::validator($definition, $collectionName === Collections::USERS);
            if (($options['validationLevel'] ?? null) !== 'strict'
                || ($options['validationAction'] ?? null) !== 'error'
                || self::normalize($options['validator'] ?? null) !== self::normalize($expectedValidator)) {
                $invalidValidators[] = $collectionName;
            }
        }

        foreach (Schema::indexes() as $collectionName => $expectedIndexes) {
            if (!in_array($collectionName, $existingCollections, true)) {
                continue;
            }
            try {
                $actualByName = [];
                foreach ($database->selectCollection($collectionName)->listIndexes() as $index) {
                    $actualByName[$index->getName()] = $index;
                }
                foreach ($expectedIndexes as $expected) {
                    $index = $actualByName[$expected['name']] ?? null;
                    $expectedPartial = $expected['partialFilterExpression'] ?? null;
                    $actualPartial = $index['partialFilterExpression'] ?? null;
                    $matches = $index !== null
                        && $index->getKey() === $expected['key']
                        && $index->isUnique() === ($expected['unique'] ?? false)
                        && self::normalize($actualPartial) === self::normalize($expectedPartial)
                        && !isset($index['expireAfterSeconds'])
                        && !($index['hidden'] ?? false)
                        && !($index['sparse'] ?? false);
                    if (!$matches) {
                        $missingIndexes[] = $collectionName . '.' . $expected['name'];
                    }
                }
            } catch (Throwable) {
                $missingIndexes[] = $collectionName . '.*';
            }
        }

        $checks = [
            'collections' => ['status' => $missingCollections === [] ? 'ok' : 'failed', 'missing' => $missingCollections],
            'validators' => ['status' => $invalidValidators === [] ? 'ok' : 'failed', 'invalid' => $invalidValidators],
            'indexes' => ['status' => $missingIndexes === [] ? 'ok' : 'failed', 'invalid' => $missingIndexes],
        ];
        return [
            'status' => $missingCollections === [] && $invalidValidators === [] && $missingIndexes === []
                ? 'ready'
                : 'not_ready',
            'checks' => $checks,
        ];
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
            $value = $value->getArrayCopy();
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }
        return $value;
    }
}
