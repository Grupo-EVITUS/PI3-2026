<?php
declare(strict_types=1);

namespace Saa\Services;

use MongoDB\BSON\ObjectId;
use Saa\Core\Request;
use Saa\Database\Bson;
use Saa\Models\AuditEventModel;

/**
 * Trilha de auditoria (RNF de rastreabilidade): grava em audit_events quem fez o quê, quando (at) e de onde.
 * Quando ator e IP não são informados, usa o usuário e o IP da requisição em andamento (se houver).
 * Ações em UPPER_SNAKE (constantes abaixo); details nunca recebe senhas, tokens ou hashes.
 */
final class AuditService
{
    public const LOGIN_SUCCESS = 'LOGIN_SUCCESS';
    public const LOGIN_FAILED = 'LOGIN_FAILED';
    public const LOGIN_BLOCKED = 'LOGIN_BLOCKED';
    public const LOGOUT = 'LOGOUT';
    public const USER_CREATED = 'USER_CREATED';

    public function __construct(
        private readonly AuditEventModel $events,
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * @param ObjectId|string|null $entityId id do registro afetado (hex de 24 caracteres vira ObjectId)
     * @param array<string, mixed> $details dados adicionais (nunca senhas, tokens ou hashes)
     * @param array{id?: string, name?: string}|null $actor quem agiu; padrão: usuário da requisição
     */
    public function record(
        string $action,
        string $entityType,
        ObjectId|string|null $entityId = null,
        array $details = [],
        ?array $actor = null,
        ?string $ip = null,
    ): void {
        $actor ??= $this->request?->user();
        $name = isset($actor['name']) && is_string($actor['name']) && $actor['name'] !== '' ? mb_substr($actor['name'], 0, 150) : null;
        $this->events->insert([
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId instanceof ObjectId || $entityId === null ? $entityId : (Bson::objectId($entityId) ?? mb_substr($entityId, 0, 100)),
            'actor_user_id' => Bson::objectId($actor['id'] ?? null),
            // sem usuário: "Visitante" numa requisição web (ex.: tentativa de login) e "Sistema" fora dela (linha de comando)
            'actor_label' => $name ?? ($this->request !== null || $ip !== null ? 'Visitante' : 'Sistema'),
            'details' => $details,
            'ip' => $ip ?? $this->request?->ip(),
        ]);
    }
}
