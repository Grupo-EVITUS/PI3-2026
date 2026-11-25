<?php
declare(strict_types=1);

namespace Saa\Database;

use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;

/** Consulta dos funcionários (usuários internos). Nunca lê nem devolve o hash de senha. */
final class MongoStaffDirectory
{
    public const LIMIT = 200;
    public const PROFILES = ['AOE', 'COORDENACAO', 'DIRETOR'];
    private const MAX_SEARCH_BYTES = 100;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * Ordenados por nome. Filtros opcionais, combinados com E: texto no nome ou no e-mail,
     * perfil e situação (true = ativos, false = inativos).
     *
     * @return list<array{id: string, name: string, email: string, profile: string, active: bool, created_at: ?string}>
     */
    public function list(?string $search = null, ?string $profile = null, ?bool $active = null): array
    {
        $search = $search === null ? '' : trim($search);
        if (strlen($search) > self::MAX_SEARCH_BYTES || !mb_check_encoding($search, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $search) === 1) {
            throw new InvalidArgumentException('Busca inválida.');
        }
        if ($profile !== null && !in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Perfil inválido.');
        }
        $filter = [];
        if ($search !== '') {
            $term = Bson::contains($search);
            $filter['$or'] = [['name' => $term], ['email' => $term]];
        }
        if ($profile !== null) {
            $filter['profile'] = $profile;
        }
        if ($active !== null) {
            $filter['active'] = $active;
        }
        $rows = [];
        foreach ($this->database->users->find($filter, [
            'projection' => ['name' => 1, 'email' => 1, 'profile' => 1, 'active' => 1, 'created_at' => 1],
            'sort' => ['name' => 1, '_id' => 1], 'limit' => self::LIMIT,
        ]) as $user) {
            $rows[] = [
                'id' => (string)$user['_id'], 'name' => $user['name'], 'email' => $user['email'],
                'profile' => $user['profile'], 'active' => (bool)$user['active'],
                'created_at' => ($user['created_at'] ?? null) instanceof UTCDateTime
                    ? $user['created_at']->toDateTime()->format('Y-m-d H:i:s') : null,
            ];
        }
        return $rows;
    }
}
