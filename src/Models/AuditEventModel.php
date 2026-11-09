<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use Saa\Database\Bson;
use Saa\Database\Collections;
use stdClass;

/**
 * Coleção audit_events (auditoria): quando (at), quem (actor_user_id/actor_label), o quê (action), sobre qual
 * registro (entity_type/entity_id), de onde (ip) e detalhes (details, documento). Só se insere e se lê: eventos de
 * auditoria nunca são alterados nem removidos pela aplicação.
 */
final class AuditEventModel extends Model
{
    protected const COLLECTION = Collections::AUDIT_EVENTS;

    /**
     * @param array<string, mixed> $event at, action, entity_type, entity_id, actor_user_id, actor_label, details, ip
     */
    public function insert(array $event): ObjectId
    {
        $event['_id'] = new ObjectId();
        $event['at'] ??= Bson::now();
        if (!isset($event['details']) || $event['details'] === []) {
            $event['details'] = new stdClass();
        }
        $this->collection()->insertOne($event);
        return $event['_id'];
    }

    /** @return list<array<string, mixed>> mais recentes primeiro */
    public function latest(int $limit = 50): array
    {
        return $this->list([], $limit);
    }

    /** @return list<array<string, mixed>> */
    public function findByAction(string $action, int $limit = 50): array
    {
        return $this->list(['action' => $action], $limit);
    }

    /** @return list<array<string, mixed>> */
    public function findByEntity(string $entityType, ObjectId|string $entityId, int $limit = 50): array
    {
        $id = Bson::objectId($entityId) ?? (string)$entityId;
        return $this->list(['entity_type' => $entityType, 'entity_id' => $id], $limit);
    }

    /**
     * @param array<string, mixed> $filter filtro montado aqui com valores tipados
     * @return list<array<string, mixed>>
     */
    private function list(array $filter, int $limit): array
    {
        $cursor = $this->collection()->find($filter, ['sort' => ['at' => -1, '_id' => -1], 'limit' => max(1, min(500, $limit))]);
        return array_values($cursor->toArray());
    }
}
