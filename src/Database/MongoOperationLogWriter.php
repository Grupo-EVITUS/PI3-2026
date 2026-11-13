<?php
declare(strict_types=1);

namespace Saa\Database;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Driver\Session;
use RuntimeException;

/** Gravador append-only para logs Mongo; não substitui a auditoria PDO do runtime atual. */
final class MongoOperationLogWriter
{
    private const MAX_ACTION_BYTES = 100;
    private const MAX_ENTITY_BYTES = 100;
    private const MAX_ENTITY_ID_BYTES = 128;
    private const MAX_DETAILS_BYTES = 4096;

    public function __construct(private readonly Collection $logs)
    {
    }

    public function append(
        ?ObjectId $userId,
        string $action,
        string $entity,
        ObjectId|string|null $entityId,
        string $details = '',
        ?Session $session = null
    ): ObjectId {
        self::validateLabel($action, self::MAX_ACTION_BYTES, 'ação');
        self::validateLabel($entity, self::MAX_ENTITY_BYTES, 'entidade');
        if (strlen($details) > self::MAX_DETAILS_BYTES || str_contains($details, "\0")
            || !mb_check_encoding($details, 'UTF-8')
        ) {
            throw new InvalidArgumentException('Detalhes da auditoria excedem o limite ou contêm bytes inválidos.');
        }

        $normalizedEntityId = $entityId instanceof ObjectId ? (string)$entityId : $entityId;
        if ($normalizedEntityId !== null && (
            trim($normalizedEntityId) === ''
            || strlen($normalizedEntityId) > self::MAX_ENTITY_ID_BYTES
            || preg_match('/[\x00-\x1F\x7F]/', $normalizedEntityId) === 1
            || !mb_check_encoding($normalizedEntityId, 'UTF-8')
        )) {
            throw new InvalidArgumentException('Identificador de entidade inválido.');
        }

        $document = [
            'user_id' => $userId,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $normalizedEntityId,
            'details' => $details,
            'happened_at' => new UTCDateTime(),
        ];
        $options = $session === null ? [] : ['session' => $session];
        $result = $this->logs->insertOne($document, $options);

        $id = $result->getInsertedId();
        if (!$id instanceof ObjectId) {
            throw new RuntimeException('O MongoDB não retornou o identificador do log de auditoria.');
        }
        return $id;
    }

    private static function validateLabel(string $value, int $maxBytes, string $field): void
    {
        if (trim($value) === '' || strlen($value) > $maxBytes
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            || !mb_check_encoding($value, 'UTF-8')
        ) {
            throw new InvalidArgumentException('Campo de auditoria inválido: ' . $field . '.');
        }
    }
}
