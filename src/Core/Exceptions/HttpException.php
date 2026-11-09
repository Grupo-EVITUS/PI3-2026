<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erro com status HTTP. O Kernel (Saa\Core\App) converte exceções desta família em respostas HTML ou JSON.
 * Mensagens são exibidas ao usuário: escreva-as em pt-BR e sem detalhes internos.
 */
class HttpException extends RuntimeException
{
    /** @param array<string, string> $headers cabeçalhos extras da resposta (ex.: Allow, Retry-After) */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Código estável (snake_case) usado nas respostas JSON de erro. */
    public function errorCode(): string
    {
        return 'http_error';
    }
}
