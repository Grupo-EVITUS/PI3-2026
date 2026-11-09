<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use Saa\Core\Response;

final class ResponseTest extends TestCase
{
    public function testHtmlResponseDefaults(): void
    {
        $response = Response::html('<p>oi</p>');

        self::assertSame(200, $response->status());
        self::assertSame('<p>oi</p>', $response->body());
        self::assertSame('text/html; charset=UTF-8', $response->header('Content-Type'));
    }

    public function testJsonKeepsUnicodeAndSlashesReadable(): void
    {
        $response = Response::json(['nome' => 'João', 'url' => '/home', 'preco' => 1.0], 201);

        self::assertSame(201, $response->status());
        self::assertSame('{"nome":"João","url":"/home","preco":1.0}', $response->body());
        self::assertSame('application/json; charset=UTF-8', $response->header('content-type'));
    }

    public function testJsonSubstitutesInvalidUtf8InsteadOfFailing(): void
    {
        $response = Response::json(['x' => "ab\xB1cd"]);

        self::assertStringContainsString('ab', $response->body());
        self::assertNotSame('', $response->body());
    }

    public function testJsonThrowsOnUnencodableValues(): void
    {
        $this->expectException(JsonException::class);

        Response::json(NAN);
    }

    public function testJsonErrorHasTheDocumentedShape(): void
    {
        $response = Response::jsonError('validation_error', 'Verifique os campos.', 422, ['name' => 'Informe o nome.']);

        self::assertSame(422, $response->status());
        self::assertSame(
            ['error' => ['code' => 'validation_error', 'message' => 'Verifique os campos.', 'fields' => ['name' => 'Informe o nome.']]],
            json_decode($response->body(), true)
        );
        self::assertArrayNotHasKey('fields', json_decode(Response::jsonError('not_found', 'x', 404)->body(), true)['error']);
    }

    public function testRedirectUsesSeeOtherByDefault(): void
    {
        $response = Response::redirect('/home');

        self::assertSame(303, $response->status());
        self::assertSame('/home', $response->header('Location'));
        self::assertSame('', $response->body());
        self::assertSame(302, Response::redirect('/x', 302)->status());
    }

    public function testNoContentAndText(): void
    {
        self::assertSame(204, Response::noContent()->status());
        self::assertSame('', Response::noContent()->body());
        self::assertSame('text/plain; charset=UTF-8', Response::text('ok')->header('Content-Type'));
    }

    public function testHeadersAreCaseInsensitiveAndImmutable(): void
    {
        $original = Response::html('x');
        $changed = $original->withHeader('x-custom', 'a')->withHeader('X-Custom', 'b');

        self::assertFalse($original->hasHeader('X-Custom'));
        self::assertTrue($changed->hasHeader('x-CUSTOM'));
        self::assertSame('b', $changed->header('X-CUSTOM'));
        self::assertSame('X-Custom', array_key_last($changed->headers()));
        self::assertFalse($changed->withoutHeader('X-custom')->hasHeader('X-Custom'));
        self::assertSame(404, $original->withStatus(404)->status());
        self::assertSame(200, $original->status());
        self::assertNull($original->header('Nope'));
    }

    public function testConstructorHeadersOverrideDefaults(): void
    {
        $response = Response::html('x', 200, ['content-type' => 'text/html; charset=ISO-8859-1']);

        self::assertSame('text/html; charset=ISO-8859-1', $response->header('Content-Type'));
        self::assertCount(1, array_filter(array_keys($response->headers()), static fn (string $n): bool => strtolower($n) === 'content-type'));
    }

    public function testHeaderInjectionIsRejected(): void
    {
        foreach (["/home\r\nSet-Cookie: x=1", "/home\nX: y", "ok\0", "ok\x1b"] as $value) {
            try {
                Response::redirect($value);
                self::fail('deveria recusar o valor com caracteres de controle');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Response::html('x')->withHeader("X-Bad\r\nInjected", 'v');
    }

    public function testTabsAreAllowedInHeaderValues(): void
    {
        self::assertSame("a\tb", Response::html('x')->withHeader('X-Tab', "a\tb")->header('X-Tab'));
    }
}
