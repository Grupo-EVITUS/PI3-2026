<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use Throwable;

/** 401 — não autenticado ou credenciais inválidas (a mensagem de login é sempre genérica). */
final class AuthenticationException extends HttpException
{
    public function __construct(string $message = 'É necessário entrar para continuar.', ?Throwable $previous = null)
    {
        parent::__construct(401, $message, [], $previous);
    }

    public function errorCode(): string
    {
        return 'unauthenticated';
    }
}
