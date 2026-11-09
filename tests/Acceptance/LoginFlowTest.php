<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use Saa\Core\SecurityHeaders;
use Saa\Database\Collections;
use Saa\Database\Mongo;
use Saa\Tests\Support\HttpClient;
use Saa\Tests\Support\HttpTestCase;

/**
 * Fluxo de login ponta a ponta contra o servidor embutido do PHP e o MongoDB real (issues #21, #22, #27):
 * formulário, CSRF, credenciais, injeção NoSQL, cookie de sessão, rotas protegidas, redirecionamento seguro,
 * logout, limite de tentativas, usuário inativo, cabeçalhos de segurança e /health.
 */
final class LoginFlowTest extends HttpTestCase
{
    public function testLoginPageShowsFormWithCsrfToken(): void
    {
        $response = $this->http->get('/login');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('text/html', $response->contentType());
        self::assertStringContainsString('<form', $response->body);
        self::assertStringContainsString('name="email"', $response->body);
        self::assertStringContainsString('name="password"', $response->body);
        self::assertMatchesRegularExpression('/name="csrf_token"\s+value="[a-f0-9]{64}"/', $response->body);
        self::assertStringContainsString('/forgot-password', $response->body);
    }

    public function testPostWithoutCsrfTokenIsForbidden(): void
    {
        $this->createUser();
        $this->http->get('/login');

        $withoutToken = $this->http->post('/login', ['email' => 'maria@escola.local', 'password' => self::PASSWORD]);
        $wrongToken = $this->http->post('/login', ['csrf_token' => str_repeat('a', 64), 'email' => 'maria@escola.local', 'password' => self::PASSWORD]);

        foreach ([$withoutToken, $wrongToken] as $response) {
            self::assertSame(403, $response->status);
            self::assertStringContainsString('Sessão expirada ou formulário inválido. Recarregue a página e tente novamente.', $response->text());
        }
        self::assertSame(303, $this->http->get('/home')->status, 'o login não pode ter acontecido');
    }

    public function testInvalidCredentialsReturnTheSameGenericMessage(): void
    {
        $this->createUser('maria@escola.local');
        $this->createUser('inativo@escola.local', 'AOE', active: false);

        $wrongPassword = $this->login('maria@escola.local', 'senha-errada-123');
        $unknownUser = $this->login('ninguem@escola.local', 'senha-errada-123');
        $inactive = $this->login('inativo@escola.local');

        foreach ([$wrongPassword, $unknownUser, $inactive] as $response) {
            self::assertSame(401, $response->status);
            self::assertStringContainsString('E-mail ou senha inválidos.', $response->text());
            self::assertStringNotContainsString('inativo', mb_strtolower($response->text()), 'não revelar que a conta existe e está inativa');
            self::assertNull($response->location());
        }
    }

    public function testBlankFieldsAreValidationErrors(): void
    {
        $page = $this->http->get('/login');
        $response = $this->http->post('/login', ['csrf_token' => $this->csrfToken($page), 'email' => '', 'password' => '']);

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Informe o e-mail.', $response->text());
        self::assertStringContainsString('Informe a senha.', $response->text());
    }

    public function testNoSqlInjectionThroughArrayFieldsDoesNotAuthenticate(): void
    {
        $this->createUser('maria@escola.local');
        $token = $this->csrfToken($this->http->get('/login'));

        $operators = $this->http->post('/login', 'csrf_token=' . $token . '&email[$ne]=x&password[$ne]=x');
        $regex = $this->http->post('/login', 'csrf_token=' . $token . '&email[$regex]=.*&password=' . self::PASSWORD);
        $json = $this->http->postJson('/login', (string)json_encode(['email' => ['$ne' => null], 'password' => ['$ne' => null], 'csrf_token' => $token]));

        foreach ([$operators, $regex, $json] as $response) {
            self::assertContains($response->status, [401, 422], 'status inesperado: ' . $response->status);
            self::assertNull($response->location());
        }
        self::assertSame(303, $this->http->get('/home')->status, 'nenhuma sessão pode ter sido aberta');
        self::assertSame(0, Mongo::collection(Collections::USERS)->countDocuments(['last_login_at' => ['$ne' => null]]));
    }

    public function testValidLoginRedirectsToHomeWithHardenedSessionCookie(): void
    {
        $this->createUser('maria@escola.local');

        $response = $this->login('  Maria@Escola.Local ');

        self::assertSame(303, $response->status);
        self::assertSame('/home', $response->location());
        $cookie = $response->setCookie('SAASESSID');
        self::assertNotNull($cookie, 'o cookie de sessão deve ser enviado');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('path=/', strtolower($cookie));
        self::assertStringNotContainsString('Secure', $cookie, 'sobre HTTP simples o cookie não é Secure');
    }

    public function testSessionIdIsRegeneratedOnLogin(): void
    {
        $this->createUser('maria@escola.local');
        $this->http->get('/login');
        $before = $this->http->cookie('SAASESSID');
        self::assertNotNull($before);

        $this->login('maria@escola.local');

        self::assertNotNull($this->http->cookie('SAASESSID'));
        self::assertNotSame($before, $this->http->cookie('SAASESSID'), 'o id de sessão deve mudar no login (anti-fixação)');
    }

    public function testFixedSessionIdFromAttackerIsNotAccepted(): void
    {
        $this->createUser('maria@escola.local');
        $attacker = new HttpClient(self::baseUrl());
        $attacker->setCookie('SAASESSID', 'abcdefghijklmnopqrstuvwxyz123456');

        $this->login('maria@escola.local', null, null, $attacker);

        self::assertNotSame('abcdefghijklmnopqrstuvwxyz123456', $attacker->cookie('SAASESSID'));
        $victim = new HttpClient(self::baseUrl());
        $victim->setCookie('SAASESSID', 'abcdefghijklmnopqrstuvwxyz123456');
        self::assertSame(303, $victim->get('/home')->status, 'o id fixado não pode dar acesso');
    }

    public function testHomeShowsUserNameGreetingAndShortcuts(): void
    {
        $this->createUser('maria@escola.local');
        $this->login('maria@escola.local');

        $home = $this->http->get('/home');

        self::assertSame(200, $home->status);
        self::assertStringContainsString('Maria Souza', $home->text());
        self::assertMatchesRegularExpression('/\b(Bom dia|Boa tarde|Boa noite)\b/', $home->text());
        self::assertStringContainsString('no-store', $home->header('cache-control') ?? '');
        self::assertStringContainsString('/me', $home->body, 'atalho para as rotas que já existem');
        self::assertStringContainsString('/profiles', $home->body);
    }

    public function testEnvironmentCardIsExclusiveToAdministrators(): void
    {
        $this->createUser('admin@escola.local', 'ADMIN');
        $this->createUser('aoe@escola.local', 'AOE', name: 'Ana Aoe');

        $this->login('admin@escola.local');
        $adminHome = $this->http->get('/home')->text();
        $aoe = new HttpClient(self::baseUrl());
        $this->login('aoe@escola.local', null, null, $aoe);
        $aoeHome = $aoe->get('/home')->text();

        self::assertStringContainsString('Servidor MongoDB', $adminHome);
        self::assertStringContainsString(self::databaseName(), $adminHome, 'o card mostra o banco conectado');
        self::assertStringNotContainsString('Servidor MongoDB', $aoeHome);
        self::assertStringNotContainsString(self::databaseName(), $aoeHome);
    }

    /** @return array<string, array{string}> */
    public static function protectedPaths(): array
    {
        return ['home' => ['/home'], 'me' => ['/me'], 'profiles' => ['/profiles']];
    }

    #[DataProvider('protectedPaths')]
    public function testProtectedRouteRedirectsGuestToLoginWithNext(string $path): void
    {
        $response = $this->http->get($path);

        self::assertSame(303, $response->status);
        self::assertSame('/login?next=' . rawurlencode($path), $response->location());
    }

    public function testProtectedRouteKeepsQueryStringInNext(): void
    {
        $response = $this->http->get('/me?tab=seguranca&x=1');

        self::assertSame(303, $response->status);
        self::assertSame('/login?next=' . rawurlencode('/me?tab=seguranca&x=1'), $response->location());
    }

    public function testNextParameterIsHonoredWhenItIsAnInternalPath(): void
    {
        $this->createUser('maria@escola.local');

        $response = $this->login('maria@escola.local', null, '/profiles');

        self::assertSame(303, $response->status);
        self::assertSame('/profiles', $response->location());
    }

    /** @return array<string, array{string}> */
    public static function hostileNextValues(): array
    {
        return [
            'protocol relative' => ['//evil.com'],
            'protocol relative with path' => ['//evil.com/home'],
            'absolute https' => ['https://evil.com/home'],
            'absolute http' => ['http://evil.com'],
            'backslash trick' => ['/\\evil.com'],
            'javascript scheme' => ['javascript:alert(1)'],
            'no leading slash' => ['evil.com'],
            'header injection' => ["/home\r\nSet-Cookie: pwned=1"],
            'back to login' => ['/login'],
        ];
    }

    #[DataProvider('hostileNextValues')]
    public function testOpenRedirectThroughNextIsIgnored(string $next): void
    {
        $this->createUser('maria@escola.local');

        $response = $this->login('maria@escola.local', null, $next);

        self::assertSame(303, $response->status);
        self::assertSame('/home', $response->location());
        foreach ($response->headerValues('set-cookie') as $cookie) {
            self::assertStringNotContainsString('pwned', $cookie, 'sem injeção de cabeçalhos');
        }
    }

    public function testNextParameterIsNotReflectedWhenHostile(): void
    {
        $page = $this->http->get('/login?next=' . rawurlencode('//evil.com'));

        self::assertSame(200, $page->status);
        self::assertStringNotContainsString('evil.com', $page->body);
    }

    public function testLogoutEndsTheSession(): void
    {
        $this->createUser('maria@escola.local');
        $this->login('maria@escola.local');
        $sessionBeforeLogout = $this->http->cookie('SAASESSID');
        self::assertSame(200, $this->http->get('/home')->status);

        $response = $this->logout();

        self::assertSame(303, $response->status);
        self::assertSame('/login', $response->location());
        self::assertSame(303, $this->http->get('/home')->status, 'depois do logout /home exige login');
        $replay = new HttpClient(self::baseUrl());
        $replay->setCookie('SAASESSID', (string)$sessionBeforeLogout);
        self::assertSame(303, $replay->get('/home')->status, 'o cookie antigo não pode ser reaproveitado');
        $loginPage = $this->http->get('/login');
        self::assertStringContainsString('Você saiu do sistema.', $loginPage->text(), 'confirmação exibida na tela de login');
        self::assertStringNotContainsString('Você saiu do sistema.', $this->http->get('/login')->text(), 'a mensagem aparece uma única vez');
    }

    public function testLogoutWithoutCsrfTokenIsForbidden(): void
    {
        $this->createUser('maria@escola.local');
        $this->login('maria@escola.local');

        $response = $this->http->post('/logout', []);

        self::assertSame(403, $response->status);
        self::assertSame(200, $this->http->get('/home')->status, 'continua autenticado');
    }

    public function testLoginAttemptsAreLimitedAndReturn429(): void
    {
        $this->createUser('maria@escola.local');
        $this->createUser('outra@escola.local', 'AOE', name: 'Outra Pessoa');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertSame(401, $this->login('maria@escola.local', 'senha-errada-123')->status, "tentativa {$attempt}");
        }
        $blocked = $this->login('maria@escola.local', 'senha-errada-123');
        $blockedEvenWithCorrectPassword = $this->login('maria@escola.local');
        $other = $this->login('outra@escola.local');

        self::assertSame(429, $blocked->status);
        self::assertGreaterThan(0, (int)$blocked->header('retry-after'));
        self::assertStringContainsString('Muitas tentativas', $blocked->text());
        self::assertSame(429, $blockedEvenWithCorrectPassword->status, 'o bloqueio vale também para a senha correta');
        self::assertSame(303, $other->status, 'outro e-mail não é bloqueado');
    }

    public function testDeactivatedUserLosesAccessOnTheNextRequest(): void
    {
        $user = $this->createUser('maria@escola.local');
        $this->login('maria@escola.local');
        self::assertSame(200, $this->http->get('/home')->status);

        Mongo::collection(Collections::USERS)->updateOne(['_id' => $user['_id']], ['$set' => ['active' => false]]);

        $response = $this->http->get('/home');
        self::assertSame(303, $response->status);
        self::assertStringStartsWith('/login', (string)$response->location());
        self::assertSame(303, $this->http->get('/me')->status);
    }

    public function testAuthenticatedUserIsRedirectedAwayFromLogin(): void
    {
        $this->createUser('maria@escola.local');
        $this->login('maria@escola.local');

        $response = $this->http->get('/login');

        self::assertSame(303, $response->status);
        self::assertSame('/home', $response->location());
    }

    public function testRootRedirectsToHome(): void
    {
        $response = $this->http->get('/');

        self::assertSame(303, $response->status);
        self::assertSame('/home', $response->location());
    }

    public function testProfilePagesShowAccountAndPermissionMatrix(): void
    {
        $this->createUser('aoe@escola.local', 'AOE', name: 'Ana Aoe');
        $this->login('aoe@escola.local');

        $me = $this->http->get('/me');
        $matrix = $this->http->get('/profiles');

        self::assertSame(200, $me->status);
        self::assertStringContainsString('aoe@escola.local', $me->text());
        self::assertStringContainsString('registry.edit', $me->body);
        self::assertSame(200, $matrix->status);
        foreach (['Administrador', 'Coordenador', 'AOE', 'Secretária', 'Responsável'] as $profile) {
            self::assertStringContainsString($profile, $matrix->text());
        }
    }

    public function testForgotPasswordIsAnInformationalPageForGuests(): void
    {
        $guest = $this->http->get('/forgot-password');

        self::assertSame(200, $guest->status);
        self::assertStringContainsString('administrador', mb_strtolower($guest->text()));
        self::assertStringNotContainsString('<form', $guest->body, 'a Etapa 1 não envia e-mail de redefinição');
    }

    public function testSecurityHeadersArePresentOnEveryKindOfResponse(): void
    {
        $responses = [
            'login' => $this->http->get('/login'),
            'health' => $this->http->get('/health'),
            'not found' => $this->http->get('/nao-existe'),
            'redirect' => $this->http->get('/home'),
            '405' => $this->http->post('/health', []),
            'csrf' => $this->http->post('/login', []),
        ];

        foreach ($responses as $name => $response) {
            self::assertSame(SecurityHeaders::CSP, $response->header('content-security-policy'), "CSP em {$name}");
            self::assertSame('nosniff', $response->header('x-content-type-options'), "nosniff em {$name}");
            self::assertSame('DENY', $response->header('x-frame-options'), "X-Frame-Options em {$name}");
            self::assertSame('same-origin', $response->header('referrer-policy'), "Referrer-Policy em {$name}");
            self::assertNotNull($response->header('permissions-policy'), "Permissions-Policy em {$name}");
            self::assertStringContainsString('no-store', $response->header('cache-control') ?? '', "Cache-Control em {$name}");
            self::assertNull($response->header('x-powered-by'), "X-Powered-By não deve vazar em {$name}");
        }
    }

    public function testHealthReportsApplicationAndMongo(): void
    {
        $response = $this->http->get('/health');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('application/json', $response->contentType());
        $data = $response->json();
        self::assertSame('ok', $data['status'] ?? null);
        self::assertTrue($data['mongo'] ?? null);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string)($data['time'] ?? ''));
    }

    public function testUnknownPathReturnsHtmlNotFound(): void
    {
        $response = $this->http->get('/nao-existe');

        self::assertSame(404, $response->status);
        self::assertStringContainsString('text/html', $response->contentType());
        self::assertStringContainsString('Página não encontrada', $response->text());
        self::assertStringNotContainsString('Stack trace', $response->body);
    }

    public function testUnknownApiPathReturnsJsonError(): void
    {
        $response = $this->http->get('/api/nao-existe', ['Accept' => 'application/json']);

        self::assertSame(404, $response->status);
        self::assertStringContainsString('application/json', $response->contentType());
        self::assertSame('not_found', $response->json()['error']['code'] ?? null);
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $response = $this->http->post('/health', []);

        self::assertSame(405, $response->status);
        self::assertStringContainsString('GET', (string)$response->header('allow'));
    }

    public function testHeadRequestsAreServedWithoutBody(): void
    {
        $response = $this->http->request('HEAD', '/health');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
    }

    public function testTraversalAttemptsOnRoutesAreHarmless(): void
    {
        foreach (['/home/../me', '/%2e%2e/%2e%2e/etc/passwd', '/home%00', '/login%0d%0aX:%20y'] as $path) {
            $response = $this->http->get($path, [], true);
            self::assertContains($response->status, [303, 400, 404], "{$path} => {$response->status}");
            self::assertNull($response->header('x'), 'sem injeção de cabeçalhos');
        }
    }
}
