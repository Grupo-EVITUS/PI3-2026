<?php
declare(strict_types=1);

namespace Saa\Core\Exceptions;

use RuntimeException;

/** Falha ao resolver uma dependência no Container (erro de programação/configuração, não do usuário). */
final class ContainerException extends RuntimeException
{
}
