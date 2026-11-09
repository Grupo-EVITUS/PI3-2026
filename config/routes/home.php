<?php
declare(strict_types=1);

use Saa\Controllers\HomeController;
use Saa\Core\Router;

/** Tela inicial (Etapa 1). "/" apenas redireciona para /home (que exige login). */
return function (Router $router): void {
    $router->get('/', [HomeController::class, 'root'], ['access' => Router::ACCESS_PUBLIC, 'name' => 'root']);
    $router->get('/home', [HomeController::class, 'index'], ['name' => 'home']);
};
