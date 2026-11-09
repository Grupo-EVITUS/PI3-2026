<?php
declare(strict_types=1);

namespace Saa\Tests\Unit\Core;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Saa\Core\Exceptions\MethodNotAllowedException;
use Saa\Core\Exceptions\NotFoundException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Core\Router;
use Saa\Security\Permissions;

final class RouterTest extends TestCase
{
    private const HANDLER = ['App\\Controller', 'action'];

    public function testResolvesStaticRoutesByMethodAndPath(): void
    {
        $router = new Router();
        $router->get('/login', self::HANDLER);
        $router->post('/login', ['App\\Controller', 'submit']);

        $get = $router->resolve('GET', '/login');
        $post = $router->resolve('POST', '/login');

        self::assertSame('action', $get['route']->handler[1]);
        self::assertSame('submit', $post['route']->handler[1]);
        self::assertSame([], $get['params']);
    }

    public function testDefaultsAreAuthAndCsrfOnlyForWritingMethods(): void
    {
        $router = new Router();
        $router->get('/a', self::HANDLER);
        $router->post('/a', self::HANDLER);
        $router->put('/b', self::HANDLER);
        $router->patch('/c', self::HANDLER);
        $router->delete('/d', self::HANDLER);

        foreach ($router->routes() as $route) {
            self::assertSame('auth', $route->access);
            self::assertNull($route->permission);
            self::assertFalse($route->api);
            self::assertSame($route->method !== 'GET', $route->csrf, $route->method . ' ' . $route->path);
        }
    }

    public function testRouteOptionsAreStored(): void
    {
        $router = new Router();
        $route = $router->post('/api/webhooks/email', self::HANDLER, ['access' => 'public', 'csrf' => false, 'api' => true, 'name' => 'webhook']);

        self::assertSame('public', $route->access);
        self::assertFalse($route->csrf);
        self::assertTrue($route->api);
        self::assertSame($route, $router->named('webhook'));
        self::assertNull($router->named('nope'));
    }

    public function testPermissionOptionMustBeKnownAndOnlyForAuthRoutes(): void
    {
        $router = new Router();
        $route = $router->get('/users', self::HANDLER, ['permission' => Permissions::USERS_MANAGE]);
        self::assertSame(Permissions::USERS_MANAGE, $route->permission);

        $this->expectException(InvalidArgumentException::class);
        $router->get('/typo', self::HANDLER, ['permission' => 'users.manager']);
    }

    public function testPermissionOnPublicRouteIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Router())->get('/x', self::HANDLER, ['access' => 'public', 'permission' => Permissions::USERS_MANAGE]);
    }

    public function testUnknownOptionsAndInvalidAccessAreRejected(): void
    {
        $router = new Router();
        foreach ([['acess' => 'public'], ['access' => 'everyone'], ['name' => '']] as $options) {
            try {
                $router->get('/x' . md5(serialize($options)), self::HANDLER, $options);
                self::fail('opção inválida deveria falhar: ' . json_encode($options));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testInvalidHandlersAreRejected(): void
    {
        $router = new Router();
        foreach ([['OnlyClass'], [1, 2], ['A', 'b', 'c'], []] as $handler) {
            try {
                $router->get('/h' . count($handler) . md5(serialize($handler)), $handler);
                self::fail('manipulador inválido deveria falhar');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $router->get('/closure', static fn (Request $request): Response => Response::text('ok'));
        self::assertTrue($router->has('/closure'));
    }

    public function testDuplicateRoutesAndNamesAreRejected(): void
    {
        $router = new Router();
        $router->get('/x', self::HANDLER, ['name' => 'x']);

        try {
            $router->get('/x', self::HANDLER);
            self::fail('rota duplicada');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $router->get('/y', self::HANDLER, ['name' => 'x']);
    }

    public function testRouteParametersAreCaptured(): void
    {
        $router = new Router();
        $router->get('/students/{id}/guardians/{guardian}', self::HANDLER);

        $match = $router->resolve('GET', '/students/abc/guardians/xyz');

        self::assertSame(['id' => 'abc', 'guardian' => 'xyz'], $match['params']);
        $this->expectException(NotFoundException::class);
        $router->resolve('GET', '/students/abc/guardians');
    }

    public function testObjectIdParameterAccepts24HexCharactersOnly(): void
    {
        $router = new Router();
        $router->get('/students/{id:oid}', self::HANDLER);
        $id = '507f1f77bcf86cd799439011';

        self::assertSame($id, $router->resolve('GET', '/students/' . $id)['params']['id']);
        self::assertSame(strtoupper($id), $router->resolve('GET', '/students/' . strtoupper($id))['params']['id']);
        foreach (['/students/123', '/students/' . $id . '0', '/students/zzzzzzzzzzzzzzzzzzzzzzzz', '/students/' . $id . '/x', '/students/' . substr($id, 1)] as $path) {
            try {
                $router->resolve('GET', $path);
                self::fail("{$path} não deveria casar");
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTokenParameterUsesTheDocumentedCharacterSet(): void
    {
        $router = new Router();
        $router->get('/justify/{token}', self::HANDLER);
        $token = str_repeat('aB3_-', 9);

        self::assertSame($token, $router->resolve('GET', '/justify/' . $token)['params']['token']);
        foreach (['/justify/curto', '/justify/' . str_repeat('a', 101), '/justify/' . str_repeat('a', 25) . '!', '/justify/' . str_repeat('a', 25) . '/'] as $path) {
            if (str_ends_with($path, '/')) {
                continue; // barra final é normalizada
            }
            try {
                $router->resolve('GET', $path);
                self::fail("{$path} não deveria casar");
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testIntegerParameterType(): void
    {
        $router = new Router();
        $router->get('/page/{n:int}', self::HANDLER);

        self::assertSame('12', $router->resolve('GET', '/page/12')['params']['n']);
        $this->expectException(NotFoundException::class);
        $router->resolve('GET', '/page/abc');
    }

    public function testUnknownParameterTypeAndRepeatedNamesAreRejected(): void
    {
        $router = new Router();
        try {
            $router->get('/a/{x:nope}', self::HANDLER);
            self::fail('tipo desconhecido');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(InvalidArgumentException::class);
        $router->get('/b/{x}/{x}', self::HANDLER);
    }

    public function testStaticRoutesWinOverDynamicOnesRegardlessOfOrder(): void
    {
        $router = new Router();
        $router->get('/students/{id}', ['App\\Controller', 'show']);
        $router->get('/students/new', ['App\\Controller', 'create']);

        self::assertSame('create', $router->resolve('GET', '/students/new')['route']->handler[1]);
        self::assertSame('show', $router->resolve('GET', '/students/other')['route']->handler[1]);
    }

    public function testGroupsApplyPrefixAndInheritedOptions(): void
    {
        $router = new Router();
        $router->group(['prefix' => '/api', 'api' => true, 'access' => 'auth'], function (Router $r): void {
            $r->get('/students', self::HANDLER, ['permission' => Permissions::REGISTRY_VIEW]);
            $r->group(['prefix' => 'public', 'access' => 'public'], function (Router $inner): void {
                $inner->get('/ping', self::HANDLER);
            });
            $r->post('/students', self::HANDLER, ['csrf' => false]);
        });
        $router->get('/outside', self::HANDLER);

        $students = $router->resolve('GET', '/api/students')['route'];
        $ping = $router->resolve('GET', '/api/public/ping')['route'];
        $write = $router->resolve('POST', '/api/students')['route'];
        $outside = $router->resolve('GET', '/outside')['route'];

        self::assertTrue($students->api);
        self::assertSame(Permissions::REGISTRY_VIEW, $students->permission);
        self::assertSame('public', $ping->access);
        self::assertTrue($ping->api, 'o grupo interno herda api=true');
        self::assertFalse($write->csrf);
        self::assertFalse($outside->api, 'o estado do grupo não vaza');
        self::assertSame('/api/students', $students->path);
    }

    public function testGroupPermissionIsInheritedAndRouteCanOverrideIt(): void
    {
        $router = new Router();
        $router->group(['permission' => Permissions::REGISTRY_VIEW], function (Router $r): void {
            $r->get('/a', self::HANDLER);
            $r->post('/a', self::HANDLER, ['permission' => Permissions::REGISTRY_EDIT]);
        });

        self::assertSame(Permissions::REGISTRY_VIEW, $router->resolve('GET', '/a')['route']->permission);
        self::assertSame(Permissions::REGISTRY_EDIT, $router->resolve('POST', '/a')['route']->permission);
    }

    public function testGroupOptionsAreValidatedAndStackIsRestoredAfterErrors(): void
    {
        $router = new Router();
        try {
            $router->group(['prefix' => '/x', 'name' => 'no'], static function (): void {
            });
            self::fail('opção name não existe em grupos');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        try {
            $router->group(['prefix' => '/boom'], static function (): void {
                throw new \RuntimeException('falha dentro do grupo');
            });
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
        $router->get('/after', self::HANDLER);

        self::assertSame('/after', $router->routes()[0]->path, 'o prefixo do grupo que falhou não pode vazar');
    }

    public function testUnknownPathIsNotFound(): void
    {
        $router = new Router();
        $router->get('/home', self::HANDLER);

        $this->expectException(NotFoundException::class);
        $router->resolve('GET', '/nope');
    }

    public function testWrongMethodIsMethodNotAllowedWithSortedAllowList(): void
    {
        $router = new Router();
        $router->get('/students', self::HANDLER);
        $router->post('/students', self::HANDLER);

        try {
            $router->resolve('DELETE', '/students');
            self::fail('deveria lançar 405');
        } catch (MethodNotAllowedException $error) {
            self::assertSame(405, $error->status());
            self::assertSame(['GET', 'HEAD', 'POST'], $error->allowed());
            self::assertSame('GET, HEAD, POST', $error->headers()['Allow']);
        }
    }

    public function testMethodNotAllowedWorksForDynamicRoutes(): void
    {
        $router = new Router();
        $router->put('/students/{id}', self::HANDLER);

        try {
            $router->resolve('GET', '/students/5');
            self::fail('deveria lançar 405');
        } catch (MethodNotAllowedException $error) {
            self::assertSame(['PUT'], $error->allowed());
        }
    }

    public function testHeadFallsBackToGetRoutes(): void
    {
        $router = new Router();
        $router->get('/health', self::HANDLER);

        self::assertSame('GET', $router->resolve('HEAD', '/health')['route']->method);
    }

    public function testMethodsAreCaseInsensitive(): void
    {
        $router = new Router();
        $router->get('/health', self::HANDLER);

        self::assertSame('GET', $router->resolve('get', '/health')['route']->method);
    }

    /** @return array<string, array{string}> */
    public static function equivalentPaths(): array
    {
        return ['trailing slash' => ['/students/'], 'plain' => ['/students'], 'double trailing' => ['/students//']];
    }

    #[DataProvider('equivalentPaths')]
    public function testTrailingSlashesAreNormalized(string $path): void
    {
        $router = new Router();
        $router->get('/students', self::HANDLER);

        self::assertSame('/students', $router->resolve('GET', $path)['route']->path);
    }

    public function testRootRoute(): void
    {
        $router = new Router();
        $router->get('/', self::HANDLER);

        self::assertSame('/', $router->resolve('GET', '/')['route']->path);
        self::assertSame('/', $router->resolve('GET', '')['route']->path);
    }

    public function testPathsWithNewlinesNeverMatch(): void
    {
        $router = new Router();
        $router->get('/home', self::HANDLER);

        foreach (["/home\n", "/home\r\n", "/ho\nme"] as $path) {
            try {
                $router->resolve('GET', $path);
                self::fail('não deveria casar: ' . json_encode($path));
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHasOnlyConsidersGetRoutesAndIgnoresQueryStrings(): void
    {
        $router = new Router();
        $router->get('/students', self::HANDLER);
        $router->post('/only-post', self::HANDLER);
        $router->get('/students/{id:oid}', self::HANDLER);

        self::assertTrue($router->has('/students'));
        self::assertTrue($router->has('/students?page=2'));
        self::assertTrue($router->has('/students/507f1f77bcf86cd799439011'));
        self::assertFalse($router->has('/only-post'));
        self::assertFalse($router->has('/missing'));
        self::assertFalse($router->has('/students/xyz'));
    }

    public function testRoutesReturnsEveryRouteInRegistrationOrder(): void
    {
        $router = new Router();
        $router->get('/a', self::HANDLER);
        $router->post('/b', self::HANDLER);
        $router->delete('/c/{id}', self::HANDLER);

        self::assertSame(['GET /a', 'POST /b', 'DELETE /c/{id}'], array_map(static fn ($r): string => $r->method . ' ' . $r->path, $router->routes()));
        self::assertTrue($router->routes()[2]->isDynamic());
        self::assertFalse($router->routes()[0]->isDynamic());
    }
}
