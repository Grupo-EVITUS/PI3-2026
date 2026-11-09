<?php
declare(strict_types=1);

use Saa\Controllers\ProfileController;
use Saa\Core\Router;

/** Conta do usuário e matriz de perfis/permissões (Etapa 1): qualquer usuário autenticado. */
return function (Router $router): void {
    $router->get('/me', [ProfileController::class, 'me'], ['name' => 'me']);
    $router->get('/profiles', [ProfileController::class, 'matrix'], ['name' => 'profiles']);
};
