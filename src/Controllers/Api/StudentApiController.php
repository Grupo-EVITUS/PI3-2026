<?php
declare(strict_types=1);

namespace Saa\Controllers\Api;

use Saa\Core\Exceptions\ValidationException;
use Saa\Core\Request;
use Saa\Core\Response;
use Saa\Core\Validator;
use Saa\Services\AttendanceService;
use Saa\Services\StudentService;

/**
 * /api/students (issue #26): listagem com busca e filtros, cadastro (com a matrícula na turma), ficha, alteração
 * parcial (PATCH), arquivamento (DELETE = arquivar, 204) e o contexto de notificação (CONSULTA 1).
 * As regras são as mesmas da tela (StudentService); matrícula escolar repetida responde 409.
 */
final class StudentApiController extends ApiController
{
    private const EDITABLE = ['name', 'student_code', 'birth_date'];

    public function __construct(
        private readonly StudentService $students,
        private readonly AttendanceService $attendance,
    ) {
    }

    /** GET /api/students?q=&class_id=&status=active|archived&page=&per_page= */
    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->paging($request);
        $status = $this->statusFilter($request);
        $filters = Validator::make(['class_id' => $request->string('class_id')])->objectId('class_id')->validate();
        $list = $this->students->list($request->string('q'), $filters['class_id'] ?? null, $status, $page, $perPage);
        return $this->paginated($list['items'], $list['pagination']);
    }

    /** POST /api/students {name, student_code, birth_date, class_id} → 201 */
    public function store(Request $request): Response
    {
        try {
            $student = $this->students->create([
                'name' => $request->string('name'),
                'student_code' => $request->string('student_code'),
                'birth_date' => $request->string('birth_date'),
                'class_id' => $request->string('class_id'),
            ]);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [StudentService::DUPLICATE_CODE]);
        }
        return $this->created($this->detail($student['id']), '/api/students/' . $student['id']);
    }

    /** GET /api/students/{id}: dados, turma atual, responsáveis vinculados e histórico de matrículas. */
    public function show(Request $request): Response
    {
        return $this->json($this->detail((string)$request->param('id')));
    }

    /** PATCH /api/students/{id}: só os campos enviados (name, student_code, birth_date). */
    public function update(Request $request): Response
    {
        $id = (string)$request->param('id');
        $current = $this->students->show($id)['student'];
        $sent = $this->sentFields($request, self::EDITABLE);
        try {
            $this->students->update($id, $sent + [
                'name' => $current['name'],
                'student_code' => $current['student_code'],
                'birth_date' => $current['birth_date'],
            ]);
        } catch (ValidationException $error) {
            return $this->conflictOr($error, [StudentService::DUPLICATE_CODE]);
        }
        return $this->json($this->detail($id));
    }

    /** DELETE /api/students/{id}: arquiva (a matrícula ativa é encerrada; nada é apagado) → 204. */
    public function archive(Request $request): Response
    {
        $this->students->archive((string)$request->param('id'));
        return Response::noContent();
    }

    /** GET /api/students/{id}/context (occurrences.view): aluno, responsáveis que recebem aviso e faltas recentes. */
    public function context(Request $request): Response
    {
        return $this->json($this->attendance->studentContext((string)$request->param('id')));
    }

    /** @return array<string, mixed> */
    private function detail(string $id): array
    {
        $data = $this->students->show($id);
        return $data['student'] + ['guardians' => $data['guardians'], 'enrollments' => $data['history']];
    }
}
