<?php
declare(strict_types=1);

namespace Saa\Tests\Integration\Models;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Saa\Core\Clock;
use Saa\Core\Exceptions\ConflictException;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Models\UserModel;
use Saa\Support\PasswordHasher;
use Saa\Tests\Support\MongoTestCase;

final class UserModelTest extends MongoTestCase
{
    private UserModel $users;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new UserModel($this->db());
    }

    private function create(string $email = 'Mariana.Lima@Escola.com', string $profile = 'AOE'): array
    {
        return $this->users->create('Mariana Lima', $email, password_hash('SenhaForte123!', PASSWORD_DEFAULT, ['cost' => 4]), $profile);
    }

    public function testCreateNormalizesTheEmailAndNeverReturnsTheHash(): void
    {
        $user = $this->create();

        self::assertInstanceOf(ObjectId::class, $user['_id']);
        self::assertArrayNotHasKey('password_hash', $user);
        self::assertSame('mariana.lima@escola.com', $user['email_normalized']);
        self::assertSame('Mariana.Lima@Escola.com', $user['email']);
        self::assertTrue($user['active']);
        self::assertNull($user['last_login_at']);

        $stored = Mongo::collection(Collections::USERS)->findOne(['_id' => $user['_id']]);
        self::assertTrue(PasswordHasher::verify('SenhaForte123!', $stored['password_hash']), 'só o hash é gravado');
    }

    public function testDuplicateEmailIsAConflictRegardlessOfCase(): void
    {
        $this->create('mariana.lima@escola.com');
        $this->expectException(ConflictException::class);
        $this->create('MARIANA.LIMA@ESCOLA.COM');
    }

    public function testReadsHideThePasswordHashUnlessTheLoginAsksForIt(): void
    {
        $id = $this->create()['_id'];

        self::assertArrayNotHasKey('password_hash', $this->users->findById($id));
        self::assertArrayNotHasKey('password_hash', $this->users->findActiveById((string)$id));
        self::assertArrayNotHasKey('password_hash', $this->users->findByEmail('mariana.lima@escola.com'));
        self::assertArrayHasKey('password_hash', $this->users->findByEmail('  MARIANA.lima@escola.com ', true));
    }

    public function testFindActiveIgnoresArchivedUsersAndInvalidIds(): void
    {
        $id = $this->create()['_id'];
        Mongo::collection(Collections::USERS)->updateOne(['_id' => $id], ['$set' => ['active' => false]]);

        self::assertNull($this->users->findActiveById($id));
        self::assertNotNull($this->users->findById($id));
        self::assertNull($this->users->findById('não-é-um-id'));
        self::assertNull($this->users->findActiveById(null));
    }

    public function testTouchLoginAndPasswordUpdateKeepTheDocumentValid(): void
    {
        Clock::fix('2026-11-04T10:00:00Z');
        $id = $this->create()['_id'];

        Clock::fix('2026-11-05T08:30:00Z');
        $this->users->touchLogin($id);
        $this->users->updatePasswordHash($id, password_hash('OutraSenha456!', PASSWORD_DEFAULT, ['cost' => 4]));

        $stored = Mongo::collection(Collections::USERS)->findOne(['_id' => $id]);
        self::assertInstanceOf(UTCDateTime::class, $stored['last_login_at']);
        self::assertSame('2026-11-05T08:30:00+00:00', $stored['last_login_at']->toDateTime()->format(DATE_ATOM));
        self::assertTrue(PasswordHasher::verify('OutraSenha456!', $stored['password_hash']));
    }
}
