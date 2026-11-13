<?php
declare(strict_types=1);

namespace Saa\Tests\Acceptance\Api;

use Saa\Tests\Acceptance\Registry\RegistryHttpTestCase;
use Saa\Tests\Support\HttpResponse;

/**
 * Base dos testes de aceitação da API JSON (issue #26): entra pelo formulário de login (mesma sessão das telas),
 * obtém o token CSRF em GET /api/me e manda JSON com o cabeçalho X-CSRF-Token. O servidor embutido usa
 * MAIL_MODE=file (pasta temporária) e MAIL_DISPATCH=inline, então registrar uma ausência envia o e-mail na hora.
 */
abstract class ApiTestCase extends RegistryHttpTestCase
{
    /** Textos que NUNCA podem aparecer numa resposta da API. */
    protected const SECRETS = ['password_hash', 'token_hash', '/justify/', '$2y$'];

    private static string $mailDirectory = '';
    protected string $csrf = '';

    protected static function serverEnvironment(): array
    {
        return [
            'MAIL_MODE' => 'file',
            'MAIL_FILE_DIR' => self::mailDirectory(),
            'MAIL_DISPATCH' => 'inline',
            'APP_URL' => 'http://saa.escola.test',
        ];
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        if (self::$mailDirectory !== '' && is_dir(self::$mailDirectory)) {
            foreach (glob(self::$mailDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir(self::$mailDirectory);
        }
        self::$mailDirectory = '';
    }

    protected static function mailDirectory(): string
    {
        if (self::$mailDirectory === '') {
            self::$mailDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saa_api_mail_' . bin2hex(random_bytes(6));
        }
        return self::$mailDirectory;
    }

    /** @return list<string> arquivos .eml gravados pelo FileMailer do servidor */
    protected static function sentMails(): array
    {
        return glob(self::mailDirectory() . DIRECTORY_SEPARATOR . '*.eml') ?: [];
    }

    /**
     * Cria o usuário do perfil, entra pelo formulário e guarda o token CSRF devolvido por GET /api/me.
     *
     * @return array<string, mixed> o "data" de /api/me
     */
    protected function apiSignIn(string $profile = 'SECRETARY', ?string $email = null): array
    {
        $email ??= strtolower($profile) . '@escola.local';
        $this->createUser($email, $profile, name: 'Usuária ' . $profile);
        $login = $this->login($email);
        self::assertSame(303, $login->status, 'login de ' . $profile);
        $me = $this->http->get('/api/me', ['Accept' => 'application/json']);
        self::assertSame(200, $me->status, $me->body);
        $this->csrf = (string)($me->json()['data']['csrf_token'] ?? '');
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->csrf);
        return $me->json()['data'];
    }

    protected function getJson(string $path): HttpResponse
    {
        $response = $this->http->get($path, ['Accept' => 'application/json']);
        $this->assertSafeJson($response);
        return $response;
    }

    /**
     * Escrita JSON com o token CSRF (passe $csrf = null para omitir o cabeçalho).
     *
     * @param array<string, mixed>|null $body
     */
    protected function sendJson(string $method, string $path, ?array $body = null, ?string $csrf = 'session'): HttpResponse
    {
        $headers = ['Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($csrf !== null) {
            $headers['X-CSRF-Token'] = $csrf === 'session' ? $this->csrf : $csrf;
        }
        $response = $this->http->request($method, $path, $body === null ? null : (string)json_encode($body), $headers);
        $this->assertSafeJson($response);
        return $response;
    }

    /** JSON (ou 204 sem corpo), nunca redirecionamento, nunca segredos. */
    protected function assertSafeJson(HttpResponse $response): void
    {
        self::assertFalse($response->status >= 300 && $response->status < 400, 'a API nunca redireciona: ' . $response->status);
        if ($response->status !== 201) {
            self::assertNull($response->location(), 'Location só na criação (201)');
        }
        if ($response->status === 204) {
            self::assertSame('', $response->body);
            return;
        }
        self::assertStringContainsString('application/json', $response->contentType(), $response->status . ' ' . substr($response->body, 0, 300));
        self::assertIsArray(json_decode($response->body, true), 'corpo JSON válido');
        foreach (self::SECRETS as $secret) {
            self::assertStringNotContainsString($secret, $response->body, 'a resposta expõe ' . $secret);
        }
    }

    /** @param list<string> $fields */
    protected static function assertError(HttpResponse $response, int $status, string $code, array $fields = []): void
    {
        self::assertSame($status, $response->status, $response->body);
        $error = $response->json()['error'] ?? null;
        self::assertIsArray($error, 'formato {"error": {...}}');
        self::assertSame($code, $error['code'] ?? null, $response->body);
        self::assertIsString($error['message'] ?? null);
        self::assertArrayNotHasKey('data', $response->json());
        foreach ($fields as $field) {
            self::assertArrayHasKey($field, $error['fields'] ?? [], $response->body);
        }
    }

    /** @return array<string, mixed> turma 6A do período vigente e um aluno nela, com um responsável que recebe avisos */
    protected function seedSchool(): array
    {
        $period = $this->seedPeriod();
        $class = $this->seedClass($period['id']);
        $student = $this->seedStudent($class['id']);
        $guardian = $this->seedGuardian();
        $this->services()['links']->linkExisting($student['id'], ['guardian_id' => $guardian['id'], 'relationship' => 'PAI']);
        return ['period' => $period, 'class' => $class, 'student' => $student, 'guardian' => $guardian];
    }

    /** Hoje no fuso escolar (mesma regra do servidor): a matrícula criada hoje só aceita faltas a partir de hoje. */
    protected static function today(): string
    {
        return \Saa\Core\Clock::today();
    }
}
