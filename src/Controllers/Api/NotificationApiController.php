<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Services\AttendanceService;
use Saa\Services\NotificationService;

/**
 * /api/notifications (issues #26, #29 e base da #42): histórico das comunicações de uma ausência ou de um aluno
 * (status, tentativas e eventos do provedor — nunca o token nem o seu hash) e reenvio, que cria uma NOVA
 * notificação (resend_of) e preserva a original.
 */
final class NotificationApiController extends ApiController
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly NotificationService $notifications,
    ) {
    }

    /** GET /api/notifications?attendance_id=<id> ou ?student_id=<id> (occurrences.view), mais recentes primeiro. */
    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        $history = $this->attendance->notificationHistory($request->string('attendance_id'), $request->string('student_id'));
        return $this->paginateList($history, $page, $perPage);
    }

    /** POST /api/notifications/{id}/resend (communications.send) → 201 com a nova notificação. */
    public function resend(Request $request): Response
    {
        $user = $this->user($request);
        $notification = NotificationService::present($this->notifications->resend((string)$request->param('id'), $user['id']));
        return $this->created($notification, '/api/notifications?attendance_id=' . $notification['attendance_id']);
    }
}
