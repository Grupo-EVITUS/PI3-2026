<?php
declare(strict_types=1);

namespace Saa\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\Driver\Exception\BulkWriteException;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Bson;
use Saa\Database\Collections;
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
}
