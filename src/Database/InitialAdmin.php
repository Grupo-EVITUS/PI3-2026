<?php
declare(strict_types=1);

namespace Saa\Database;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use RuntimeException;

/** Cria a primeira conta administrativa uma única vez, por comando CLI explícito. */
final class InitialAdmin
{
    public static function create(Database $database, string $name, string $email, string $password): ObjectId
    {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' || strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidArgumentException('Nome inválido: informe de 1 a 120 caracteres sem controles.');
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('E-mail inválido.');
        }
        if (strlen($password) < 14 || strlen($password) > 72) {
            throw new InvalidArgumentException('A senha inicial deve ter entre 14 e 72 bytes.');
        }

        $readiness = HealthCheck::inspect($database);
        if ($readiness['status'] !== 'ready') {
            throw new RuntimeException('MongoDB não está inicializado para criar a conta inicial.');
        }

        $users = $database->selectCollection(Collections::USERS);
        if ($users->findOne([], ['projection' => ['_id' => 1]]) !== null) {
            throw new RuntimeException('A conta inicial já foi criada ou existem usuários cadastrados.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Não foi possível proteger a senha da conta inicial.');
        }
        $id = new ObjectId();
        try {
            $users->insertOne([
                '_id' => $id,
                'name' => $name,
                'email' => $email,
                'email_key' => Schema::emailKey($email),
                'password_hash' => $passwordHash,
                'profile' => 'COORDENACAO',
                'active' => true,
                'created_at' => new \MongoDB\BSON\UTCDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
                'is_initial_admin' => true,
            ]);
        } catch (BulkWriteException $error) {
            // A chave única parcial também impede duas inicializações simultâneas.
            throw new RuntimeException('Conta inicial já criada ou e-mail em uso.', 0, $error);
        }
        return $id;
    }
}
