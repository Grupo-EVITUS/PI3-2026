<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Core\Exceptions\BadRequestException;
use Saa\Core\Exceptions\HttpException;
use Saa\Core\Request;

final class RequestTest extends TestCase
{
    public function testQueryAndFormBodyAreReadSeparately(): void
    {
        $request = Request::create('POST', '/search?page=2&q=ana', ['name' => 'Maria']);

        self::assertSame('POST', $request->method());
        self::assertSame('2', $request->query('page'));
        self::assertSame('Maria', $request->post('name'));
        self::assertSame('Maria', $request->input('name'));
        self::assertSame('ana', $request->input('q'), 'input() cai para a query string');
        self::assertSame(['page' => '2', 'q' => 'ana', 'name' => 'Maria'], $request->all());
        self::assertNull($request->post('q'));
        self::assertNull($request->input('ausente'));
        self::assertSame('x', $request->input('ausente', 'x'));
    }

    public function testBodyWinsOverQueryInInputAndAll(): void
    {
        $request = Request::create('POST', '/x?name=url', ['name' => 'corpo']);

        self::assertSame('corpo', $request->input('name'));
        self::assertSame('corpo', $request->all()['name']);
    }

    public function testGetDataBecomesQueryString(): void
    {
        $request = Request::create('GET', '/lista', ['q' => 'ana', 'page' => 3]);

        self::assertSame('/lista?q=ana&page=3', $request->uri());
        self::assertSame('/lista', $request->path());
        self::assertSame('ana', $request->query('q'));
    }

    public function testStringGetterIgnoresArraysSoNoSqlOperatorsNeverGetThrough(): void
    {
        $request = Request::create('GET', '/login?email[$ne]=x&password[$gt]=&name[]=a&ok=valor');

        self::assertSame(['$ne' => 'x'], $request->input('email'), 'o valor bruto continua acessível');
        self::assertNull($request->string('email'));
        self::assertNull($request->string('password'));
        self::assertNull($request->string('name'));
        self::assertSame(['x'], $request->stringList('email'), 'só os valores de texto sobram: as chaves de operador ($ne) são descartadas');
        self::assertNull($request->int('email'));
        self::assertSame('valor', $request->string('ok'));
    }

    public function testJsonBodyOperatorObjectsAreIgnoredByTypedGetters(): void
    {
        $request = Request::create('POST', '/login', [], [], [], '{"email":{"$ne":null},"password":["a"],"n":5,"f":1.5,"b":true,"s":" texto "}');

        self::assertNull($request->string('email'));
        self::assertNull($request->string('password'));
        self::assertSame('5', $request->string('n'), 'números JSON viram texto');
        self::assertSame('1.5', $request->string('f'));
        self::assertNull($request->string('b'), 'booleanos não são texto');
        self::assertSame('texto', $request->string('s'));
        self::assertSame(5, $request->int('n'));
        self::assertTrue($request->bool('b'));
    }

    public function testStringGetterNeverReturnsNonStrings(): void
    {
        $request = Request::create('POST', '/x', [], [], [], '{"a":{"$gt":""},"b":[1,2],"c":null,"d":false}');

        foreach (['a', 'b', 'c', 'd', 'ausente'] as $field) {
            self::assertNull($request->string($field), $field);
        }
    }

    public function testStringTrimsByDefaultAndCanKeepPasswordsIntact(): void
    {
        $request = Request::create('POST', '/x', ['a' => '  Maria  ', 'senha' => '  segredo  ', 'vazio' => '']);

        self::assertSame('Maria', $request->string('a'));
        self::assertSame('  segredo  ', $request->string('senha', false));
        self::assertSame('', $request->string('vazio'));
        self::assertNull($request->string('inexistente'));
    }

    /** @return array<string, array{mixed, ?int}> */
    public static function integers(): array
    {
        return [
            'digits' => ['42', 42], 'negative' => ['-7', -7], 'spaces' => [' 15 ', 15], 'zero' => ['0', 0],
            'decimal' => ['1.5', null], 'letters' => ['abc', null], 'mixed' => ['12abc', null], 'empty' => ['', null],
            'array' => [['1'], null], 'too long' => ['99999999999999999999', null], 'hex' => ['0x1A', null],
        ];
    }

    #[DataProvider('integers')]
    public function testIntGetterOnlyAcceptsIntegers(mixed $raw, ?int $expected): void
    {
        $request = Request::create('POST', '/x', ['n' => $raw]);

        self::assertSame($expected, $request->int('n'));
    }

    public function testIntGetterFallsBackToDefault(): void
    {
        $request = Request::create('GET', '/x?page=abc');

        self::assertSame(1, $request->int('page', 1));
        self::assertSame(1, $request->int('ausente', 1));
    }

    /** @return array<string, array{mixed, bool, bool}> raw, default, expected */
    public static function booleans(): array
    {
        return [
            'one' => ['1', false, true], 'true' => ['true', false, true], 'on' => ['on', false, true], 'sim' => ['SIM', false, true],
            'zero' => ['0', true, false], 'false' => ['false', true, false], 'off' => ['off', true, false], 'empty' => ['', true, false],
            'garbage uses default true' => ['talvez', true, true], 'garbage uses default false' => ['talvez', false, false],
            'array uses default' => [['1'], false, false], 'array uses default true' => [['1'], true, true],
        ];
    }

    #[DataProvider('booleans')]
    public function testBoolGetter(mixed $raw, bool $default, bool $expected): void
    {
        $request = Request::create('POST', '/x', ['flag' => $raw]);

        self::assertSame($expected, $request->bool('flag', $default));
    }

    public function testBoolDefaultsWhenMissing(): void
    {
        $request = Request::create('GET', '/x');

        self::assertFalse($request->bool('x'));
        self::assertTrue($request->bool('x', true));
    }

    public function testStringListKeepsOnlyStrings(): void
    {
        $request = Request::create('POST', '/x', ['ids' => ['a', ' b ', ['nested'], 5, 'c'], 'one' => 'solo']);

        self::assertSame(['a', 'b', 'c'], $request->stringList('ids'));
        self::assertSame(['solo'], $request->stringList('one'));
        self::assertSame([], $request->stringList('missing'));
    }

    public function testStringListDropsOperatorMaps(): void
    {
        $request = Request::create('POST', '/x', ['ids' => ['$ne' => ['x']]]);

        self::assertSame([], $request->stringList('ids'));
    }

    public function testInvalidJsonBodyIsABadRequest(): void
    {
        $request = Request::create('POST', '/x', [], [], [], '{"email": ');

        $this->expectException(BadRequestException::class);

        $request->body();
    }

    public function testScalarJsonBodyIsABadRequest(): void
    {
        $this->expectException(BadRequestException::class);

        Request::create('POST', '/x', [], [], [], '"texto"')->all();
    }

    public function testEmptyJsonBodyIsAnEmptyArray(): void
    {
        self::assertSame([], Request::create('POST', '/x', [], ['CONTENT_TYPE' => 'application/json'], [], '')->body());
    }

    public function testOversizedJsonBodyIsRejected(): void
    {
        $request = Request::create('POST', '/x', [], [], [], '{"a":"' . str_repeat('x', Request::MAX_BODY_BYTES) . '"}');

        try {
            $request->body();
            self::fail('deveria recusar o corpo grande');
        } catch (HttpException $error) {
            self::assertSame(413, $error->status());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function paths(): array
    {
        return [
            'plain' => ['/home', '/home'],
            'query removed' => ['/students?page=2', '/students'],
            'fragment removed' => ['/x#frag', '/x'],
            'percent decoded' => ['/a%20b', '/a b'],
            'slash kept' => ['/students/', '/students/'],
            'root' => ['/', '/'],
            'protocol relative stays a path' => ['//evil.com/x', '//evil.com/x'],
            'absolute form' => ['http://exemplo.com/home?x=1', '/home'],
            'absolute form without path' => ['http://exemplo.com', '/'],
            'no leading slash' => ['home', '/home'],
            'null byte dropped' => ['/ho%00me', '/home'],
            'empty' => ['', '/'],
        ];
    }

    #[DataProvider('paths')]
    public function testPathParsing(string $uri, string $expected): void
    {
        self::assertSame($expected, (new Request('GET', $uri))->path());
    }

    public function testHeadersAreReadFromServerVariables(): void
    {
        $request = Request::create('POST', '/x', [], [
            'HTTP_X_CSRF_TOKEN' => 'abc', 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ]);

        self::assertSame('abc', $request->header('X-CSRF-Token'));
        self::assertSame('abc', $request->header('x-csrf-token'));
        self::assertSame('application/json', $request->header('Accept'));
        self::assertSame('application/x-www-form-urlencoded', $request->header('Content-Type'));
        self::assertNull($request->header('X-Ausente'));
        self::assertSame('padrao', $request->header('X-Ausente', 'padrao'));
    }

    public function testCookiesAreReadByName(): void
    {
        $request = Request::create('GET', '/x', [], [], ['SAASESSID' => 'abc123']);

        self::assertSame('abc123', $request->cookie('SAASESSID'));
        self::assertNull($request->cookie('outro'));
    }

    public function testIpUsesOnlyRemoteAddrAndIgnoresProxyHeaders(): void
    {
        $request = Request::create('GET', '/x', [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CLIENT_IP' => '5.6.7.8']);

        self::assertSame('203.0.113.9', $request->ip());
        self::assertSame('0.0.0.0', Request::create('GET', '/x', [], ['REMOTE_ADDR' => 'nao-e-ip'])->ip());
        self::assertSame('::1', Request::create('GET', '/x', [], ['REMOTE_ADDR' => '::1'])->ip());
    }

    /** @return array<string, array{array<string, string>, bool}> */
    public static function acceptHeaders(): array
    {
        return [
            'json only' => [['HTTP_ACCEPT' => 'application/json'], true],
            'json first' => [['HTTP_ACCEPT' => 'application/json, text/html'], true],
            'browser' => [['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'], false],
            'html before json' => [['HTTP_ACCEPT' => 'text/html, application/json'], false],
            'wildcard' => [['HTTP_ACCEPT' => '*/*'], false],
            'no accept' => [[], false],
            'ajax' => [['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], true],
            'json body without accept' => [['CONTENT_TYPE' => 'application/json'], true],
        ];
    }

    /** @param array<string, string> $server */
    #[DataProvider('acceptHeaders')]
    public function testWantsJson(array $server, bool $expected): void
    {
        self::assertSame($expected, Request::create('GET', '/x', [], $server)->wantsJson());
    }

    public function testIsSecureAndHost(): void
    {
        self::assertFalse(Request::create('GET', '/x')->isSecure());
        self::assertTrue(Request::create('GET', '/x', [], ['HTTPS' => 'on'])->isSecure());
        self::assertFalse(Request::create('GET', '/x', [], ['HTTPS' => 'off'])->isSecure());
        self::assertTrue(Request::create('GET', '/x', [], ['SERVER_PORT' => '443'])->isSecure());
        self::assertSame('escola.local:8000', Request::create('GET', '/x', [], ['HTTP_HOST' => 'escola.local:8000'])->host());
        self::assertSame('localhost', Request::create('GET', '/x', [], ['HTTP_HOST' => "evil.com\r\nX: y"])->host());
        self::assertSame('localhost', Request::create('GET', '/x', [], ['HTTP_HOST' => 'a b'])->host());
    }

    public function testWithParamsAndWithUserReturnCopies(): void
    {
        $original = Request::create('GET', '/students/5');
        $user = ['id' => 'a', 'name' => 'Maria', 'email' => 'm@x.y', 'profile' => 'ADMIN'];

        $routed = $original->withParams(['id' => '5'])->withUser($user);

        self::assertNull($original->param('id'));
        self::assertNull($original->user());
        self::assertSame('5', $routed->param('id'));
        self::assertSame(['id' => '5'], $routed->params());
        self::assertSame('x', $routed->param('outro', 'x'));
        self::assertSame($user, $routed->user());
    }

    public function testHasChecksBodyAndQuery(): void
    {
        $request = Request::create('POST', '/x?a=1', ['b' => '2']);

        self::assertTrue($request->has('a'));
        self::assertTrue($request->has('b'));
        self::assertFalse($request->has('c'));
    }
}
