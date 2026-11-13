<?php
declare(strict_types=1);

use Saa\Controllers\UserController;
use Saa\Core\Router;
use Saa\Security\Permissions;

/**
 * Usuários internos (Etapa 2 — issues #36 e #37): cadastro, busca, edição de perfil/situação e nova senha definida
 * pelo administrador. Todas as rotas exigem users.manage (somente o perfil ADMIN). IDs são ObjectId ({id:oid}).
 */
return function (Router $router): void {
    $manage = ['permission' => Permissions::USERS_MANAGE];

    $router->get('/users', [UserController::class, 'index'], $manage);
    $router->post('/users', [UserController::class, 'store'], $manage);
    $router->get('/users/{id:oid}', [UserController::class, 'edit'], $manage);
    $router->post('/users/{id:oid}', [UserController::class, 'update'], $manage);
    $router->post('/users/{id:oid}/password', [UserController::class, 'password'], $manage);
};
