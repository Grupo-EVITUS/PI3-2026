<?php
declare(strict_types=1);

namespace Saa\Core;

use Saa\Core\Exceptions\CsrfException;

/**
 * Proteção CSRF: um token aleatório por sessão (renovado no login), comparado com hash_equals.
 * O token pode vir no campo "csrf_token" do corpo (formulário ou JSON) ou no cabeçalho X-CSRF-Token.
 * Nunca é aceito pela query string (apareceria em logs e no Referer).
 */
final class Csrf
{
    public const FIELD = 'csrf_token';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf';

    public function __construct(private readonly Session $session)
    {
    }

    /** Token da sessão atual (criado sob demanda). */
    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) < 32) {
            $token = $this->generate();
        }
        return $token;
    }

    /** Gera um token novo (use após login/logout). */
    public function rotate(): string
    {
        return $this->generate();
    }

    /** Campo hidden pronto para formulários. */
    public function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="' . htmlspecialchars($this->token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /** true se o token enviado coincide com o da sessão. Sem token na sessão, a resposta é sempre false. */
    public function isValid(Request $request): bool
    {
        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($expected) || $expected === '') {
            return false;
        }
        $submitted = $request->header(self::HEADER);
        $fromBody = $request->post(self::FIELD);
        if (is_string($fromBody) && $fromBody !== '') {
            $submitted = $fromBody;
        }
        return is_string($submitted) && $submitted !== '' && hash_equals($expected, $submitted);
    }

    /** @throws CsrfException (HTTP 403) se o token for inválido */
    public function verify(Request $request): void
    {
        if (!$this->isValid($request)) {
            throw new CsrfException();
        }
    }

    private function generate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->put(self::SESSION_KEY, $token);
        return $token;
    }
}
