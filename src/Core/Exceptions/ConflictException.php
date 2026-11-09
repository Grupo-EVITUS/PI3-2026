<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use Throwable;

/** 409 — conflito com o estado atual (ex.: e-mail ou matrícula já cadastrados). */
final class ConflictException extends HttpException
{
    public function __construct(string $message = 'Já existe um registro com estes dados.', ?Throwable $previous = null)
    {
        parent::__construct(409, $message, [], $previous);
    }

    public function errorCode(): string
    {
        return 'conflict';
    }
}
