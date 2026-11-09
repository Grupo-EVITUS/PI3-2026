<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Saa\Core\Csrf;
use Saa\Core\Exceptions\CsrfException;
use Saa\Core\Request;
use Saa\Core\Session;

final class CsrfTest extends TestCase
{
    private Session $session;
    private Csrf $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = Session::memory();
        $this->session->start();
        $this->csrf = new Csrf($this->session);
    }

    public function testTokenIsRandomHexAndStableWithinTheSession(): void
    {
        $token = $this->csrf->token();

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        self::assertSame($token, $this->csrf->token());
        self::assertNotSame($token, (new Csrf($this->newSession()))->token(), 'cada sessão tem o seu token');
    }

    public function testRotateIssuesANewToken(): void
    {
        $old = $this->csrf->token();

        $new = $this->csrf->rotate();

        self::assertNotSame($old, $new);
        self::assertSame($new, $this->csrf->token());
    }

    public function testFieldRendersAHiddenInputWithTheToken(): void
    {
        $field = $this->csrf->field();

        self::assertSame('<input type="hidden" name="csrf_token" value="' . $this->csrf->token() . '">', $field);
    }

    public function testValidTokenInFormBodyIsAccepted(): void
    {
        $request = Request::create('POST', '/x', ['csrf_token' => $this->csrf->token()]);

        self::assertTrue($this->csrf->isValid($request));
        $this->csrf->verify($request);
    }

    public function testValidTokenInHeaderIsAccepted(): void
    {
        $request = Request::create('POST', '/api/x', [], ['HTTP_X_CSRF_TOKEN' => $this->csrf->token()]);

        self::assertTrue($this->csrf->isValid($request));
    }

    public function testValidTokenInJsonBodyIsAccepted(): void
    {
        $request = Request::create('POST', '/api/x', [], [], [], json_encode(['csrf_token' => $this->csrf->token()]));

        self::assertTrue($this->csrf->isValid($request));
    }

    public function testMissingOrWrongTokenIsRejected(): void
    {
        $this->csrf->token();

        $attempts = [
            'missing' => Request::create('POST', '/x', ['a' => 'b']),
            'wrong' => Request::create('POST', '/x', ['csrf_token' => str_repeat('0', 64)]),
            'empty' => Request::create('POST', '/x', ['csrf_token' => '']),
            'array' => Request::create('POST', '/x', ['csrf_token' => [$this->csrf->token()]]),
            'prefix only' => Request::create('POST', '/x', ['csrf_token' => substr($this->csrf->token(), 0, 32)]),
            'wrong header' => Request::create('POST', '/x', [], ['HTTP_X_CSRF_TOKEN' => 'abc']),
        ];

        foreach ($attempts as $name => $request) {
            self::assertFalse($this->csrf->isValid($request), $name);
        }
    }

    public function testTokenInTheQueryStringIsNeverAccepted(): void
    {
        $request = Request::create('POST', '/x?csrf_token=' . $this->csrf->token());

        self::assertFalse($this->csrf->isValid($request));
    }

    public function testBodyTokenTakesPrecedenceOverTheHeader(): void
    {
        $request = Request::create('POST', '/x', ['csrf_token' => 'errado'], ['HTTP_X_CSRF_TOKEN' => $this->csrf->token()]);

        self::assertFalse($this->csrf->isValid($request));
    }

    public function testVerifyThrowsTheDocumentedForbiddenError(): void
    {
        $this->csrf->token();

        try {
            $this->csrf->verify(Request::create('POST', '/x'));
            self::fail('deveria lançar CsrfException');
        } catch (CsrfException $error) {
            self::assertSame(403, $error->status());
            self::assertSame('Sessão expirada ou formulário inválido. Recarregue a página e tente novamente.', $error->getMessage());
            self::assertSame('csrf_invalid', $error->errorCode());
        }
    }

    public function testValidationNeverMintsATokenForSessionsWithoutOne(): void
    {
        $session = $this->newSession();
        $csrf = new Csrf($session);

        self::assertFalse($csrf->isValid(Request::create('POST', '/x', ['csrf_token' => ''])));
        self::assertFalse($csrf->isValid(Request::create('POST', '/x', ['csrf_token' => 'qualquer'])));
        self::assertNull($session->get('_csrf'), 'verificar não pode criar token');
    }

    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $other = (new Csrf($this->newSession()))->token();
        $this->csrf->token();

        self::assertFalse($this->csrf->isValid(Request::create('POST', '/x', ['csrf_token' => $other])));
    }

    private function newSession(): Session
    {
        $session = Session::memory();
        $session->start();
        return $session;
    }
}
