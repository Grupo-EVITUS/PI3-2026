<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\Regex;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;
use Saa\Domain\Profile;
use Saa\Support\Email;

/**
 * Coleção users (usuários internos: ADMIN, COORDINATION, AOE, SECRETARY).
 * Campos: name, email, email_normalized (ÚNICO), password_hash, profile, active, last_login_at, password_changed_at,
 * created_at, updated_at, created_by. Leituras gerais NÃO trazem password_hash: só findByEmail(..., true) (login).
 */
final class UserModel extends Model
{
    protected const COLLECTION = Collections::USERS;
    private const WITHOUT_HASH = ['projection' => ['password_hash' => 0]];

    public function normalizeEmail(string $email): string
    {
        return Email::normalize($email);
    }

    /**
     * Cria um usuário ativo e devolve o documento SEM password_hash.
     *
     * @return array<string, mixed>
     * @throws ConflictException e-mail já cadastrado
     */
    public function create(string $name, string $email, string $passwordHash, string $profile, ?ObjectId $createdBy = null): array
    {
        $normalized = $this->normalizeEmail($email);
        if ($this->collection()->countDocuments(['email_normalized' => $normalized], ['limit' => 1]) > 0) {
            throw new ConflictException('Já existe um usuário com este e-mail.');
        }
        $now = Bson::now();
        $document = [
            '_id' => new ObjectId(),
            'name' => $name,
            'email' => trim($email),
            'email_normalized' => $normalized,
            'password_hash' => $passwordHash,
            'profile' => $profile,
            'active' => true,
            'last_login_at' => null,
            'password_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $createdBy,
        ];
        try {
            $this->collection()->insertOne($document);
        } catch (BulkWriteException $error) {
            if (self::isDuplicateKey($error)) {
                throw new ConflictException('Já existe um usuário com este e-mail.', $error);
            }
            throw $error;
        }
        unset($document['password_hash']);
        return $document;
    }

    /**
     * Busca pelo e-mail (normalizado). $withPasswordHash = true SOMENTE no login.
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email, bool $withPasswordHash = false): ?array
    {
        $user = $this->collection()->findOne(
            ['email_normalized' => $this->normalizeEmail($email)],
            $withPasswordHash ? [] : self::WITHOUT_HASH
        );
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null usuário (ativo ou não) sem password_hash; id inválido => null */
    public function findById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $user = $this->collection()->findOne(['_id' => $oid], self::WITHOUT_HASH);
        return is_array($user) ? $user : null;
    }

    /** @return array<string, mixed>|null usuário ATIVO sem password_hash; inexistente, inativo ou id inválido => null */
    public function findActiveById(ObjectId|string|null $id): ?array
    {
        $oid = Bson::objectId($id);
        if ($oid === null) {
            return null;
        }
        $user = $this->collection()->findOne(['_id' => $oid, 'active' => true], self::WITHOUT_HASH);
        return is_array($user) ? $user : null;
    }

    /** Registra o instante do último login. */
    public function touchLogin(ObjectId|string $id): void
    {
        $oid = Bson::objectId($id);
        if ($oid !== null) {
            $this->collection()->updateOne(['_id' => $oid], ['$set' => ['last_login_at' => Bson::now()]]);
        }
    }

    /** Troca o hash (atualização de custo/algoritmo no login). Não altera password_changed_at. */
    public function updatePasswordHash(ObjectId|string $id, string $passwordHash): void
    {
        $oid = Bson::objectId($id);
        if ($oid !== null) {
            $this->collection()->updateOne(['_id' => $oid], ['$set' => ['password_hash' => $passwordHash]]);
        }
    }

    // ------------------------------------------------------------------ gestão de usuários (issues #36 e #37)

    /**
     * Busca paginada (sem password_hash) por nome ou e-mail, com filtro de perfil e de situação, numa só agregação.
     *
     * @param Regex|null  $term    filtro "contém" já escapado (Bson::contains), aplicado ao nome e ao e-mail normalizado
     * @param string|null $profile código do perfil já validado (null = todos)
     * @param bool|null   $active  true = ativos, false = inativos, null = todos
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(?Regex $term, ?string $profile, ?bool $active, int $skip, int $limit): array
    {
        $match = [];
        if ($term !== null) {
            $match['$or'] = [['name' => $term], ['email_normalized' => $term]];
        }
        if ($profile !== null) {
            $match['profile'] = $profile;
        }
        if ($active !== null) {
            $match['active'] = $active;
        }
        $pipeline = [
            ['$match' => $match === [] ? new \stdClass() : $match],
            ['$project' => ['password_hash' => 0]],
            ['$facet' => [
                'items' => [
                    ['$sort' => ['name' => 1, '_id' => 1]],
                    ['$skip' => max(0, $skip)],
                    ['$limit' => max(1, $limit)],
                ],
                'total' => [['$count' => 'n']],
            ]],
        ];
        $result = $this->collection()->aggregate($pipeline)->toArray();
        $facet = $result[0] ?? ['items' => [], 'total' => []];
        return [
            'items' => array_values($facet['items'] ?? []),
            'total' => (int)($facet['total'][0]['n'] ?? 0),
        ];
    }

    /**
     * Atualiza nome, perfil e situação (lista branca: outros campos são ignorados).
     *
     * @param array{name?: string, profile?: string, active?: bool} $fields valores já validados pelo serviço
     * @return bool true se o usuário existe
     */
    public function updateAccount(ObjectId $id, array $fields): bool
    {
        $set = array_intersect_key($fields, ['name' => true, 'profile' => true, 'active' => true]);
        $set['updated_at'] = Bson::now();
        $result = $this->collection()->updateOne(['_id' => $id], ['$set' => $set]);
        return $result->getMatchedCount() === 1;
    }

    /**
     * Grava uma nova senha definida pelo administrador (hash já calculado) e atualiza password_changed_at.
     *
     * @return bool true se o usuário existe
     */
    public function setPassword(ObjectId $id, string $passwordHash): bool
    {
        $now = Bson::now();
        $result = $this->collection()->updateOne(
            ['_id' => $id],
            ['$set' => ['password_hash' => $passwordHash, 'password_changed_at' => $now, 'updated_at' => $now]]
        );
        return $result->getMatchedCount() === 1;
    }

    /** Quantos administradores ativos existem (opcionalmente desconsiderando um usuário). Usa ix_users_profile_active. */
    public function countActiveAdmins(?ObjectId $except = null): int
    {
        $filter = ['profile' => Profile::ADMIN->value, 'active' => true];
        if ($except !== null) {
            $filter['_id'] = ['$ne' => $except];
        }
        return $this->collection()->countDocuments($filter);
    }
}
