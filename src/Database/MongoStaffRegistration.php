<?php
declare(strict_types=1);

namespace Saa\Database;

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Session;
use RuntimeException;
use function MongoDB\with_transaction;

final class MongoStaffRegistration
{
    public function __construct(private Database $database)
    {
    }

    public function register(ObjectId $actor, string $name, string $email, string $password, string $profile): ObjectId
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !in_array($profile, ['AOE', 'COORDENACAO', 'DIRETOR'], true)
            || strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new InvalidArgumentException('Dados de cadastro inválidos.');
        }
        $id = new ObjectId();
        $user = [
            '_id' => $id, 'name' => $name, 'email' => $email, 'email_key' => Schema::emailKey($email),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'profile' => $profile,
            'active' => true, 'created_at' => new UTCDateTime(),
        ];
        $session = $this->database->getManager()->startSession();
        try {
            with_transaction($session, function (Session $session) use ($actor, $id, $user): void {
                $options = ['session' => $session];
                $authorized = $this->database->users->findOne([
                    '_id' => $actor, 'profile' => 'COORDENACAO', 'active' => true,
                ], $options);
                if ($authorized === null) {
                    throw new RuntimeException('Cadastro permitido somente à coordenação ativa.');
                }
                $this->database->users->insertOne($user, $options);
                $writer = new MongoOperationLogWriter($this->database->operation_logs);
                $writer->append($actor, 'USUARIO_CRIADO', 'usuario', $id, '', $session);
            });
            return $id;
        } finally {
            $session->endSession();
        }
    }
}
