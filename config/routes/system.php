<?php
declare(strict_types=1);

use Saa\Controllers\HealthController;
use Saa\Core\Router;

/** Rotas de infraestrutura. /health é público (monitoramento) e não expõe dados sensíveis. */
return function (Router $router): void {
    $router->get('/health', [HealthController::class, 'show'], ['access' => Router::ACCESS_PUBLIC, 'name' => 'health']);
};
