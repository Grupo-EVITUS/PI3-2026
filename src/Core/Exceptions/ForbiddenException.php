<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use Throwable;

/** 403 — autenticado, mas sem permissão para a operação. */
class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Seu perfil não tem acesso a esta página.', ?Throwable $previous = null)
    {
        parent::__construct(403, $message, [], $previous);
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }
}
