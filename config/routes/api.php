<?php
declare(strict_types=1);

use Saa\Controllers\Api\AttendanceApiController;
use Saa\Controllers\Api\ClassApiController;
use Saa\Controllers\Api\EnrollmentApiController;
use Saa\Controllers\Api\GuardianApiController;
use Saa\Controllers\Api\MeController;
use Saa\Controllers\Api\NotificationApiController;
use Saa\Controllers\Api\PeriodApiController;
use Saa\Controllers\Api\StudentApiController;
use Saa\Core\Router;
use Saa\Security\Permissions;

/**
 * API JSON (issue #26; docs/ARQUITETURA.md, seção 10). Mesma sessão e mesmas permissões das telas; todas as rotas
 * são "api" (erros sempre em JSON, 401 sem sessão em vez de redirecionar). Escritas (POST/PATCH/DELETE) exigem o
 * cabeçalho X-CSRF-Token, obtido em GET /api/me. DELETE de cadastro = arquivar (204).
 */
return function (Router $router): void {
    $router->group(['prefix' => '/api', 'api' => true], function (Router $router): void {
        $view = ['permission' => Permissions::REGISTRY_VIEW];
        $edit = ['permission' => Permissions::REGISTRY_EDIT];
        $occurrences = ['permission' => Permissions::OCCURRENCES_VIEW];

        // Sessão: usuário, perfil, permissões e token CSRF (qualquer usuário autenticado)
        $router->get('/me', [MeController::class, 'show'], ['name' => 'api.me']);

        // Períodos letivos
        $router->get('/periods', [PeriodApiController::class, 'index'], $view);

        // Alunos
        $router->get('/students', [StudentApiController::class, 'index'], $view);
        $router->post('/students', [StudentApiController::class, 'store'], $edit);
        $router->get('/students/{id:oid}', [StudentApiController::class, 'show'], $view);
        $router->patch('/students/{id:oid}', [StudentApiController::class, 'update'], $edit);
        $router->delete('/students/{id:oid}', [StudentApiController::class, 'archive'], $edit);
        $router->get('/students/{id:oid}/context', [StudentApiController::class, 'context'], $occurrences);

        // Turmas
        $router->get('/classes', [ClassApiController::class, 'index'], $view);
        $router->post('/classes', [ClassApiController::class, 'store'], $edit);
        $router->get('/classes/{id:oid}', [ClassApiController::class, 'show'], $view);
        $router->patch('/classes/{id:oid}', [ClassApiController::class, 'update'], $edit);
        $router->delete('/classes/{id:oid}', [ClassApiController::class, 'archive'], $edit);

        // Responsáveis
        $router->get('/guardians', [GuardianApiController::class, 'index'], $view);
        $router->post('/guardians', [GuardianApiController::class, 'store'], $edit);
        $router->get('/guardians/{id:oid}', [GuardianApiController::class, 'show'], $view);
        $router->patch('/guardians/{id:oid}', [GuardianApiController::class, 'update'], $edit);
        $router->delete('/guardians/{id:oid}', [GuardianApiController::class, 'archive'], $edit);

        // Matrículas (histórico e troca de turma)
        $router->get('/enrollments', [EnrollmentApiController::class, 'index'], $view);
        $router->post('/enrollments', [EnrollmentApiController::class, 'store'], $edit);

        // Frequência e ausências
        $router->get('/attendance', [AttendanceApiController::class, 'index'], $occurrences);
        $router->post('/attendance', [AttendanceApiController::class, 'store'], ['permission' => Permissions::ABSENCES_REGISTER]);
        $router->get('/attendance/{id:oid}', [AttendanceApiController::class, 'show'], $occurrences);

        // Comunicações por e-mail
        $router->get('/notifications', [NotificationApiController::class, 'index'], $occurrences);
        $router->post('/notifications/{id:oid}/resend', [NotificationApiController::class, 'resend'], ['permission' => Permissions::COMMUNICATIONS_SEND]);
    });
};
