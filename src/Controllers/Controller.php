<?php
declare(strict_types=1);

namespace Saa\Controllers;

use InvalidArgumentException;
use LogicException;
use Saa\Core\App;
use Saa\Core\Exceptions\AuthenticationException;
use Saa\Core\Request;
use Saa\Core\Response;

/**
 * Base dos Controllers: só HTTP (Request → Response). Regras de negócio ficam em Services; nada de consultas ao
 * MongoDB aqui. Cada ação tem a assinatura  public function acao(Request $request): Response  e pode receber
 * Services no construtor (autowiring pelo Container; subclasses não precisam chamar parent::__construct).
 */
abstract class Controller
{
    protected const LAYOUT_APP = 'layouts/app';
    protected const LAYOUT_AUTH = 'layouts/auth';
    protected const LAYOUT_PUBLIC = 'layouts/public';

    /**
     * Renderiza views/<template>.php dentro do layout. Para formulários com erro use status 422 e passe as
     * chaves "errors" (campo => mensagem) e "old" (valores digitados, sem senhas — veja oldInput()).
     *
     * @param array<string, mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200, string $layout = self::LAYOUT_APP): Response
    {
        return Response::html($this->app()->view()->render($template, $data, $layout), $status);
    }

    /** Redireciona (303, padrão PRG) para um caminho interno. */
    protected function redirect(string $to, int $status = 303): Response
    {
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || preg_match('/[\x00-\x1F\x7F\\\\]/', $to) === 1) {
            throw new InvalidArgumentException('O redirecionamento só aceita caminhos internos seguros.');
        }
        return Response::redirect($to, $status);
    }

    /**
     * Resposta JSON de sucesso: {"data": ..., "meta": {...}}.
     *
     * @param array<string, mixed>|null $meta paginação etc. (meta.page, meta.per_page, meta.total)
     */
    protected function json(mixed $data, int $status = 200, ?array $meta = null): Response
    {
        $body = ['data' => $data];
        if ($meta !== null) {
            $body['meta'] = $meta;
        }
        return Response::json($body, $status);
    }

    /**
     * Resposta JSON de erro: {"error": {"code", "message", "fields"?}}.
     *
     * @param array<string, string> $fields
     */
    protected function jsonError(string $code, string $message, int $status = 400, array $fields = []): Response
    {
        return Response::jsonError($code, $message, $status, $fields);
    }

    /** Mensagem exibida uma única vez na próxima página (success | error | info | warning). */
    protected function flash(string $kind, string $message): void
    {
        $this->app()->session()->flash($kind, $message);
    }

    /**
     * Usuário autenticado da requisição.
     *
     * @return array{id: string, name: string, email: string, profile: string}
     */
    protected function user(Request $request): array
    {
        return $request->user() ?? throw new AuthenticationException();
    }

    /**
     * Valores digitados para repopular o formulário após um erro: remove senhas, tokens e segredos.
     *
     * @return array<string, mixed>
     */
    protected function oldInput(Request $request): array
    {
        $old = [];
        foreach ($request->all() as $key => $value) {
            if (!is_string($key) || preg_match('/pass|senha|token|secret/i', $key) === 1) {
                continue;
            }
            $old[$key] = $value;
        }
        return $old;
    }

    private function app(): App
    {
        return App::current() ?? throw new LogicException('Nenhuma aplicação ativa.');
    }
}
