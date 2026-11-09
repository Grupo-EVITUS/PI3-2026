<?php
declare(strict_types=1);

namespace Saa\Core;

use Closure;

/** Rota registrada no Router (objeto de valor). Criada por Router::get/post/put/patch/delete. */
final class Route
{
    /**
     * @param array{0: class-string, 1: string}|Closure $handler [Controller::class, 'metodo'] ou closure
     * @param list<string> $paramNames nomes dos parâmetros do caminho, em ordem
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array|Closure $handler,
        public readonly string $access,
        public readonly ?string $permission,
        public readonly bool $api,
        public readonly bool $csrf,
        public readonly ?string $name,
        public readonly string $regex,
        public readonly array $paramNames,
    ) {
    }

    /** true se o caminho tem parâmetros ({id}, {token}...). */
    public function isDynamic(): bool
    {
        return $this->paramNames !== [];
    }
}
