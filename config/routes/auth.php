<?php
declare(strict_types=1);

use Saa\Controllers\AuthController;
use Saa\Core\Router;

/** Entrada e saída do sistema (Etapa 1). O fluxo completo de redefinição de senha chega na Etapa 3. */
return function (Router $router): void {
    $router->get('/login', [AuthController::class, 'showLogin'], ['access' => Router::ACCESS_GUEST, 'name' => 'login']);
    $router->post('/login', [AuthController::class, 'login'], ['access' => Router::ACCESS_GUEST, 'name' => 'login.attempt']);
    $router->post('/logout', [AuthController::class, 'logout'], ['name' => 'logout']);
    $router->get('/forgot-password', [AuthController::class, 'forgotPassword'], ['access' => Router::ACCESS_GUEST, 'name' => 'password.forgot']);
};
