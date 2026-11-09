<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use Throwable;

/** 400 — requisição malformada (ex.: corpo JSON inválido). */
final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Requisição inválida.', ?Throwable $previous = null)
    {
        parent::__construct(400, $message, [], $previous);
    }

    public function errorCode(): string
    {
        return 'bad_request';
    }
}
