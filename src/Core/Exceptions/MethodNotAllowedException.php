<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

/** 405 — o caminho existe, mas não para o método usado. Responde com o cabeçalho Allow. */
final class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowed métodos aceitos para o caminho */
    public function __construct(private readonly array $allowed)
    {
        parent::__construct(
            405,
            'Este endereço não aceita o método utilizado.',
            ['Allow' => implode(', ', $allowed)]
        );
    }

    /** @return list<string> */
    public function allowed(): array
    {
        return $this->allowed;
    }

    public function errorCode(): string
    {
        return 'method_not_allowed';
    }
}
