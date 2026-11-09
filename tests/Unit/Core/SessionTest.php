<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use LogicException;
use PHPUnit\Framework\TestCase;
use Saa\Core\Clock;
use Saa\Core\Config;
use Saa\Core\Session;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
        Clock::fix('2026-03-10 12:00:00');
    }

    protected function tearDown(): void
    {
        Clock::fix(null);
        Config::reset();
        parent::tearDown();
    }

    private function started(): Session
    {
        $session = Session::memory();
        $session->start();
        return $session;
    }

    public function testMemorySessionStoresReadsAndForgetsValues(): void
    {
        $session = $this->started();

        $session->put('cor', 'azul');

        self::assertSame('azul', $session->get('cor'));
        self::assertTrue($session->has('cor'));
        self::assertSame('x', $session->get('outra', 'x'));
        self::assertSame('azul', $session->pull('cor'));
        self::assertFalse($session->has('cor'));
        $session->put('a', 1);
        $session->forget('a');
        self::assertNull($session->get('a'));
        self::assertFalse($session->isNative());
        self::assertTrue($session->isStarted());
    }

    public function testReadingBeforeStartIsHarmlessButWritingFails(): void
    {
        $session = Session::memory();

        self::assertNull($session->get('x'));
        self::assertSame([], $session->pullFlashes());
        self::assertNull($session->userId());
        $session->forget('x');
        $this->expectException(LogicException::class);
        $session->put('x', 1);
    }

    public function testUserIdAcceptsOnlyNonEmptyStrings(): void
    {
        $session = $this->started();
        self::assertNull($session->userId());

        $session->setUserId('507f1f77bcf86cd799439011');
        self::assertSame('507f1f77bcf86cd799439011', $session->userId());

        $session->put(Session::USER_KEY, ['array']);
        self::assertNull($session->userId());
        $session->put(Session::USER_KEY, '');
        self::assertNull($session->userId());
    }

    public function testFlashMessagesAreReadOnce(): void
    {
        $session = $this->started();

        $session->flash('success', 'Salvo.');
        $session->flash('error', 'Falhou.');
        $session->flash('desconhecido', 'Cai em info.');

        self::assertSame([
            ['kind' => 'success', 'message' => 'Salvo.'],
            ['kind' => 'error', 'message' => 'Falhou.'],
            ['kind' => 'info', 'message' => 'Cai em info.'],
        ], $session->pullFlashes());
        self::assertSame([], $session->pullFlashes());
    }

    public function testRegenerateChangesTheIdentifierAndKeepsTheData(): void
    {
        $session = $this->started();
        $session->put('x', 'y');
        $before = $session->id();

        $session->regenerate();

        self::assertNotSame($before, $session->id());
        self::assertSame('y', $session->get('x'));
        self::assertSame(1, $session->regenerationCount());
    }

    public function testInvalidateDropsEverythingAndChangesTheIdentifier(): void
    {
        $session = $this->started();
        $session->setUserId('abc');
        $session->flash('info', 'antiga');
        $before = $session->id();

        $session->invalidate('revoked');

        self::assertNotSame($before, $session->id());
        self::assertNull($session->userId());
        self::assertSame([], $session->pullFlashes());
        self::assertSame('revoked', $session->expiredReason());
        $session->flash('success', 'nova');
        self::assertCount(1, $session->pullFlashes(), 'a sessão nova aceita dados');
    }

    public function testFlushKeepsTheIdentifier(): void
    {
        $session = $this->started();
        $session->put('x', 1);
        $id = $session->id();

        $session->flush();

        self::assertSame($id, $session->id());
        self::assertNull($session->get('x'));
    }

    public function testIdleTimeoutEndsAnAuthenticatedSession(): void
    {
        Config::set('SESSION_IDLE_MINUTES', '30');
        $session = $this->started();
        $session->setUserId('abc');

        Clock::fix('2026-03-10 12:29:00');
        $session->start();
        self::assertSame('abc', $session->userId(), 'dentro do limite de inatividade');
        self::assertNull($session->expiredReason());

        Clock::fix('2026-03-10 13:00:00'); // 31 min depois do último acesso (12:29)
        $session->start();
        self::assertNull($session->userId());
        self::assertSame('idle', $session->expiredReason());
    }

    public function testActivityExtendsTheIdleWindow(): void
    {
        Config::set('SESSION_IDLE_MINUTES', '30');
        $session = $this->started();
        $session->setUserId('abc');

        foreach (['12:25', '12:50', '13:15', '13:40'] as $time) {
            Clock::fix('2026-03-10 ' . $time . ':00');
            $session->start();
            self::assertSame('abc', $session->userId(), "às {$time}");
        }
    }

    public function testAbsoluteTimeoutEndsTheSessionEvenWhenActive(): void
    {
        Config::set('SESSION_IDLE_MINUTES', '120');
        Config::set('SESSION_ABSOLUTE_HOURS', '2');
        $session = $this->started();
        $session->setUserId('abc');

        foreach (['12:50', '13:40'] as $time) {
            Clock::fix('2026-03-10 ' . $time . ':00');
            $session->start();
            self::assertSame('abc', $session->userId(), "às {$time}");
        }

        Clock::fix('2026-03-10 14:01:00'); // 2h01 depois do início, mas só 21 min depois do último acesso
        $session->start();
        self::assertNull($session->userId());
        self::assertSame('absolute', $session->expiredReason());
    }

    public function testExpiredGuestSessionDoesNotReportAnExpiration(): void
    {
        Config::set('SESSION_IDLE_MINUTES', '10');
        $session = $this->started();
        $session->put('x', 'y');

        Clock::fix('2026-03-10 14:00:00');
        $session->start();

        self::assertNull($session->get('x'));
        self::assertNull($session->expiredReason(), 'visitante sem login não ganha aviso de expiração');
    }

    public function testExpirationReasonIsClearedOnTheNextRequest(): void
    {
        Config::set('SESSION_IDLE_MINUTES', '10');
        $session = $this->started();
        $session->setUserId('abc');
        Clock::fix('2026-03-10 14:00:00');
        $session->start();
        self::assertSame('idle', $session->expiredReason());

        Clock::fix('2026-03-10 14:01:00');
        $session->start();

        self::assertNull($session->expiredReason());
    }

    public function testInvalidTimeoutConfigurationFallsBackToSafeMinimums(): void
    {
        Config::set('SESSION_IDLE_MINUTES', '0');
        Config::set('SESSION_ABSOLUTE_HOURS', '-5');
        $session = $this->started();
        $session->setUserId('abc');

        Clock::fix('2026-03-10 12:00:30');
        $session->start();

        self::assertSame('abc', $session->userId(), 'zero ou negativo vira o mínimo de 1 (nunca expira a cada requisição)');
    }

    public function testSaveIsHarmlessForMemorySessions(): void
    {
        $session = $this->started();
        $session->put('x', 1);

        $session->save();

        self::assertSame(1, $session->get('x'));
    }
}
