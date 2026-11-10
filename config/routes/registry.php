<?php
declare(strict_types=1);

use Saa\Controllers\ClassController;
use Saa\Controllers\GuardianController;
use Saa\Controllers\PeriodController;
use Saa\Controllers\StudentController;
use Saa\Core\Router;
use Saa\Security\Permissions;

/**
 * Cadastros da Etapa 2 (Sprint 5 — issues #38, #39, #40, #41): períodos letivos, turmas, alunos, responsáveis e
 * matrículas. Consultar (GET) exige registry.view; alterar (POST) exige registry.edit. IDs são ObjectId ({id:oid}).
 */
return function (Router $router): void {
    $view = ['permission' => Permissions::REGISTRY_VIEW];
    $edit = ['permission' => Permissions::REGISTRY_EDIT];

    // Períodos letivos
    $router->get('/periods', [PeriodController::class, 'index'], $view);
    $router->post('/periods', [PeriodController::class, 'store'], $edit);
    $router->get('/periods/{id:oid}', [PeriodController::class, 'edit'], $view);
    $router->post('/periods/{id:oid}', [PeriodController::class, 'update'], $edit);
    $router->post('/periods/{id:oid}/activate', [PeriodController::class, 'activate'], $edit);

    // Turmas
    $router->get('/classes', [ClassController::class, 'index'], $view);
    $router->post('/classes', [ClassController::class, 'store'], $edit);
    $router->get('/classes/{id:oid}', [ClassController::class, 'show'], $view);
    $router->post('/classes/{id:oid}', [ClassController::class, 'update'], $edit);
    $router->post('/classes/{id:oid}/students', [ClassController::class, 'enroll'], $edit);
    $router->post('/classes/{id:oid}/archive', [ClassController::class, 'archive'], $edit);

    // Alunos
    $router->get('/students', [StudentController::class, 'index'], $view);
    $router->post('/students', [StudentController::class, 'store'], $edit);
    $router->get('/students/{id:oid}', [StudentController::class, 'show'], $view);
    $router->post('/students/{id:oid}', [StudentController::class, 'update'], $edit);
    $router->post('/students/{id:oid}/archive', [StudentController::class, 'archive'], $edit);
    $router->post('/students/{id:oid}/reactivate', [StudentController::class, 'reactivate'], $edit);
    $router->post('/students/{id:oid}/guardians', [StudentController::class, 'linkGuardian'], $edit);
    $router->post('/students/{id:oid}/guardians/{gid:oid}', [StudentController::class, 'updateGuardian'], $edit);
    $router->post('/students/{id:oid}/guardians/{gid:oid}/remove', [StudentController::class, 'removeGuardian'], $edit);
    $router->post('/students/{id:oid}/enrollment', [StudentController::class, 'changeClass'], $edit);

    // Responsáveis
    $router->get('/guardians', [GuardianController::class, 'index'], $view);
    $router->post('/guardians', [GuardianController::class, 'store'], $edit);
    $router->get('/guardians/{id:oid}', [GuardianController::class, 'show'], $view);
    $router->post('/guardians/{id:oid}', [GuardianController::class, 'update'], $edit);
    $router->post('/guardians/{id:oid}/archive', [GuardianController::class, 'archive'], $edit);
    $router->post('/guardians/{id:oid}/reactivate', [GuardianController::class, 'reactivate'], $edit);
};
