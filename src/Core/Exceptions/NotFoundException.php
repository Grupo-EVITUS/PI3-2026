<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use Throwable;

/** 404 — rota inexistente ou registro não encontrado. */
final class NotFoundException extends HttpException
{
    public function __construct(string $message = 'O conteúdo solicitado não foi encontrado.', ?Throwable $previous = null)
    {
        parent::__construct(404, $message, [], $previous);
    }

    public function errorCode(): string
    {
        return 'not_found';
    }
}
