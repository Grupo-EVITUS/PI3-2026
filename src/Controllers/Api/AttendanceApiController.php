<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Services\AttendanceService;

/**
 * /api/attendance (issues #26 e #29): consulta de frequência com filtros, detalhe com a justificativa e o
 * status_history, e registro de ausência pelo AttendanceService (ocorrência PENDING + um e-mail por responsável
 * válido). Ausência repetida no mesmo dia responde 409 (ConflictException do serviço).
 */
final class AttendanceApiController extends ApiController
{
    private const FILTERS = ['student_id', 'class_id', 'date_from', 'date_to', 'status', 'justification_status'];

    public function __construct(private readonly AttendanceService $attendance)
    {
    }

    /** GET /api/attendance?student_id=&class_id=&date_from=&date_to=&status=&justification_status=&page=&per_page= */
    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        $filters = [];
        foreach (self::FILTERS as $field) {
            $filters[$field] = $request->string($field);
        }
        $list = $this->attendance->search($filters, $page, $perPage);
        return $this->paginated($list['items'], $list['pagination']);
    }

    /**
     * POST /api/attendance {student_id, date (AAAA-MM-DD), note?} (absences.register) → 201 com o registro, o resumo
     * das notificações criadas (sem links nem tokens) e os avisos (ex.: nenhum responsável com e-mail válido).
     */
    public function store(Request $request): Response
    {
        $user = $this->user($request);
        $result = $this->attendance->registerAbsence(
            $request->string('student_id') ?? '',
            $request->string('date') ?? '',
            $user['id'],
            $request->string('note'),
        );
        $id = (string)$result['attendance']['_id'];
        return $this->created([
            'attendance' => $this->attendance->get($id),
            'converted_from_present' => $result['converted_from_present'],
            'notifications' => $result['notifications'],
            'warnings' => $result['warnings'],
        ], '/api/attendance/' . $id);
    }

    /** GET /api/attendance/{id}: registro com a justificativa e o status_history. */
    public function show(Request $request): Response
    {
        return $this->json($this->attendance->get((string)$request->param('id')));
    }
}
