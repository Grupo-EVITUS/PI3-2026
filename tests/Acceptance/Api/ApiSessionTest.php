<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Api;

use Saa\Core\Config;
use Saa\Core\Router;
use Saa\Security\Permissions;

/**
 * Sessão, CSRF e formato da API JSON (issue #26; docs/ARQUITETURA.md, seção 10): sem sessão → 401 em JSON (nunca
 * redirecionamento); GET /api/me entrega usuário, perfil, permissões e o token CSRF; escrita sem X-CSRF-Token ou
 * com token errado → 403 (csrf_invalid, o mesmo status que o núcleo usa nas telas); erros sempre {"error": {...}}.
 */
final class ApiSessionTest extends ApiTestCase
{
    public function testIssue26EveryApiRouteIsJsonWithPermissionAndCsrfOnWrites(): void
    {
        $router = new Router();
        (require Config::basePath('config/routes/api.php'))($router);

        $paths = [];
        foreach ($router->routes() as $route) {
            $label = $route->method . ' ' . $route->path;
            $paths[] = $label;
            self::assertStringStartsWith('/api/', $route->path, $label);
            self::assertTrue($route->api, $label . ' precisa de api => true');
            self::assertSame('auth', $route->access, $label);
            self::assertSame($route->method !== 'GET', $route->csrf, $label);
            if ($route->path !== '/api/me') {
                self::assertNotNull($route->permission, $label . ' precisa de permission');
            }
            if (str_starts_with($route->path, '/api/students') || str_starts_with($route->path, '/api/classes')
                || str_starts_with($route->path, '/api/guardians') || str_starts_with($route->path, '/api/enrollments')
                || $route->path === '/api/periods') {
                $expected = $route->method === 'GET' ? Permissions::REGISTRY_VIEW : Permissions::REGISTRY_EDIT;
                if ($route->path === '/api/students/{id:oid}/context') {
                    $expected = Permissions::OCCURRENCES_VIEW;
                }
                self::assertSame($expected, $route->permission, $label);
            }
        }
        foreach ([
            'GET /api/me', 'GET /api/periods', 'GET /api/students', 'POST /api/students', 'GET /api/students/{id:oid}',
            'PATCH /api/students/{id:oid}', 'DELETE /api/students/{id:oid}', 'GET /api/students/{id:oid}/context',
            'GET /api/classes', 'POST /api/classes', 'PATCH /api/classes/{id:oid}', 'DELETE /api/classes/{id:oid}',
            'GET /api/guardians', 'POST /api/guardians', 'PATCH /api/guardians/{id:oid}', 'DELETE /api/guardians/{id:oid}',
            'GET /api/enrollments', 'POST /api/enrollments', 'GET /api/attendance', 'POST /api/attendance',
            'GET /api/attendance/{id:oid}', 'GET /api/notifications', 'POST /api/notifications/{id:oid}/resend',
        ] as $expected) {
            self::assertContains($expected, $paths);
        }
        self::assertSame(Permissions::ABSENCES_REGISTER, $router->resolve('POST', '/api/attendance')['route']->permission);
        self::assertSame(Permissions::OCCURRENCES_VIEW, $router->resolve('GET', '/api/attendance')['route']->permission);
        self::assertSame(Permissions::COMMUNICATIONS_SEND, $router->resolve('POST', '/api/notifications/' . str_repeat('a', 24) . '/resend')['route']->permission);
    }

    public function testIssue26WithoutSessionTheApiAnswers401JsonAndNeverRedirects(): void
    {
        foreach (['/api/me', '/api/students', '/api/periods', '/api/attendance', '/api/students/' . str_repeat('a', 24)] as $path) {
            foreach ([['Accept' => 'application/json'], []] as $headers) {
                $response = $this->http->get($path, $headers);
                self::assertSame(401, $response->status, $path);
                self::assertNull($response->location(), $path);
                self::assertStringContainsString('application/json', $response->contentType(), $path);
                self::assertSame('unauthenticated', $response->json()['error']['code'] ?? null, $path);
            }
        }
    }

    public function testIssue26MeReturnsUserProfilePermissionsAndCsrfTokenWithoutSecrets(): void
    {
        $data = $this->apiSignIn('AOE', 'aoe@escola.local');

        self::assertSame('aoe@escola.local', $data['user']['email']);
        self::assertSame('AOE', $data['user']['profile']);
        self::assertSame('AOE', $data['user']['profile_label']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $data['user']['id']);
        self::assertSame(Permissions::forProfile('AOE'), $data['permissions']);
        self::assertContains(Permissions::ABSENCES_REGISTER, $data['permissions']);

        $me = $this->getJson('/api/me');
        self::assertSame($this->csrf, $me->json()['data']['csrf_token'], 'o token é o mesmo durante a sessão');
        self::assertStringContainsString('no-store', strtolower((string)$me->header('cache-control')), 'o token CSRF não pode ficar em cache');
    }

    public function testIssue26WritesWithoutOrWithWrongCsrfHeaderAre403(): void
    {
        $school = $this->seedSchool();
        $this->apiSignIn('SECRETARY');
        $payload = ['name' => 'Davi Rocha', 'student_code' => 'RA-900', 'birth_date' => '2014-08-20', 'class_id' => $school['class']['id']];

        $missing = $this->sendJson('POST', '/api/students', $payload, null);
        self::assertError($missing, 403, 'csrf_invalid');
        $wrong = $this->sendJson('POST', '/api/students', $payload, str_repeat('0', 64));
        self::assertError($wrong, 403, 'csrf_invalid');
        foreach (['PATCH', 'DELETE'] as $method) {
            self::assertError($this->sendJson($method, '/api/students/' . $school['student']['id'], ['name' => 'X'], null), 403, 'csrf_invalid');
        }
        self::assertSame(0, $this->getJson('/api/students?q=Davi')->json()['meta']['total'], 'nada foi gravado');

        $created = $this->sendJson('POST', '/api/students', $payload);
        self::assertSame(201, $created->status, $created->body);
    }

    public function testIssue26WriteWithoutSessionIsRejectedAsJson(): void
    {
        $response = $this->sendJson('POST', '/api/students', ['name' => 'X'], str_repeat('a', 64));

        // o núcleo confere o CSRF antes do acesso (docs/ARQUITETURA.md, seção 4): sem sessão não há token válido
        self::assertError($response, 403, 'csrf_invalid');
    }

    public function testIssue26UnknownPathAndWrongMethodAreJsonErrors(): void
    {
        $this->apiSignIn('ADMIN', 'admin@escola.local');

        self::assertError($this->getJson('/api/nao-existe'), 404, 'not_found');
        self::assertError($this->getJson('/api/students/' . str_repeat('b', 24)), 404, 'not_found');
        self::assertError($this->getJson('/api/students/123'), 404, 'not_found');
        $put = $this->sendJson('PUT', '/api/students', ['name' => 'X']);
        self::assertError($put, 405, 'method_not_allowed');
        self::assertStringContainsString('POST', (string)$put->header('allow'));
    }

    public function testIssue26InvalidJsonBodyIs400(): void
    {
        $this->apiSignIn('SECRETARY');

        $response = $this->http->request('POST', '/api/students', '{"name": ', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'X-CSRF-Token' => $this->csrf,
        ]);

        self::assertError($response, 400, 'bad_request');
    }
}
