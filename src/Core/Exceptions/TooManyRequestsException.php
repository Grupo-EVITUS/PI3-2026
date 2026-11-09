<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

/** 429 — limite de tentativas excedido (ex.: login). Informa Retry-After em segundos. */
final class TooManyRequestsException extends HttpException
{
    public function __construct(
        private readonly int $retryAfterSeconds = 60,
        string $message = 'Muitas tentativas. Aguarde alguns minutos e tente novamente.',
    ) {
        $retry = max(1, $retryAfterSeconds);
        parent::__construct(429, $message, ['Retry-After' => (string)$retry]);
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds);
    }

    public function errorCode(): string
    {
        return 'too_many_requests';
    }
}
