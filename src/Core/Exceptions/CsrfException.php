<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use Throwable;

/** 403 — token CSRF ausente ou inválido (sessão expirada ou formulário desatualizado). */
final class CsrfException extends ForbiddenException
{
    public const MESSAGE = 'Sessão expirada ou formulário inválido. Recarregue a página e tente novamente.';

    public function __construct(string $message = self::MESSAGE, ?Throwable $previous = null)
    {
        parent::__construct($message, $previous);
    }

    public function errorCode(): string
    {
        return 'csrf_invalid';
    }
}
