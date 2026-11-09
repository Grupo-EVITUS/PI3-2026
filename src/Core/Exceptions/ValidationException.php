<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

/**
 * 422 — dados inválidos. Carrega as mensagens por campo (campo => primeira mensagem em pt-BR).
 * Controllers normalmente capturam a exceção e re-renderizam o formulário com status 422.
 */
final class ValidationException extends HttpException
{
    /** @param array<string, string> $errors */
    public function __construct(private readonly array $errors, string $message = 'Verifique os campos informados.')
    {
        parent::__construct(422, $message);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function errorCode(): string
    {
        return 'validation_error';
    }
}
